/**
 * Y11 Concurrency Test Script (CX-1 & CX-2)
 * - CX-1: 5 concurrent POST /api/visits/{id}/examination/start on the same visit
 *         -> exactly 1 row in examinations, all 5 responses return the same examination_id
 * - CX-2: 2 concurrent POST /api/examinations/{id}/revise with version=1
 *         -> exactly 1 succeeds (200) and 1 gets conflict (409), final version=2, 1 row in examination_revisions
 */
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const PHP_BIN = 'C:\\laragon\\bin\\php\\php-8.5.12-Win32-vs17-x64\\php.exe';
const PROJECT_ROOT = 'C:/laragon/www/OTHER/SIMRS/simrs-yii';

function buildPhpApiScript(method, uri, body, token, barrierFile = null) {
  const headersJson = JSON.stringify({ Authorization: `Bearer ${token}` }).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  const bodyJson = JSON.stringify(body || {}).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  const barrierWait = barrierFile
    ? `while (!file_exists('${barrierFile.replace(/\\/g, '/')}')) { usleep(1000); }`
    : '';

  return `<?php
require '${PROJECT_ROOT}/config/dotenv.php';
require '${PROJECT_ROOT}/vendor/autoload.php';
require '${PROJECT_ROOT}/vendor/yiisoft/yii2/Yii.php';

$_SERVER['REQUEST_METHOD'] = '${method}';
$_SERVER['REQUEST_URI'] = '${uri}';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = '${PROJECT_ROOT}/web/index.php';
$_GET = [];

$headers = json_decode('${headersJson}', true);
foreach ($headers as $k => $v) {
    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
}

$data = json_decode('${bodyJson}', true);
$_POST = $data;

$config = require '${PROJECT_ROOT}/config/web.php';
$app = new yii\\web\\Application($config);
$app->request->setBodyParams($data);
$app->db->open();

${barrierWait}

$res = $app->handleRequest($app->request);
echo json_encode($res->data ?? ['content' => $res->content]);
`;
}

function runPhpCode(phpCode) {
  return new Promise((resolve, reject) => {
    const tmpFile = path.join(os.tmpdir(), `y11_cx_${Date.now()}_${Math.random().toString(36).slice(2)}.php`);
    fs.writeFileSync(tmpFile, phpCode, 'utf8');
    const proc = spawn(PHP_BIN, [tmpFile]);
    let stdout = '';
    let stderr = '';
    proc.stdout.on('data', (d) => { stdout += d.toString(); });
    proc.stderr.on('data', (d) => { stderr += d.toString(); });
    proc.on('close', () => {
      try { fs.unlinkSync(tmpFile); } catch (_) {}
      try {
        resolve(JSON.parse(stdout));
      } catch (e) {
        resolve({ raw: stdout, stderr });
      }
    });
    proc.on('error', reject);
  });
}

async function main() {
  console.log('=== Y11 CONCURRENCY TEST (CX-1 & CX-2) ===\n');

  const setupScript = `<?php
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');
require '${PROJECT_ROOT}/config/dotenv.php';
require '${PROJECT_ROOT}/vendor/autoload.php';
require '${PROJECT_ROOT}/vendor/yiisoft/yii2/Yii.php';
$config = require '${PROJECT_ROOT}/config/web.php';
new yii\\web\\Application($config);

$db = Yii::$app->db;
$sfx = strtoupper(substr(md5((string)microtime()), 0, 4));
$today = date('Y-m-d');
$dayToday = (int)date('N', strtotime($today));

$uname = 'doc_cx_' . strtolower($sfx);
$db->createCommand("INSERT INTO users (username, password_hash, full_name, roles, is_active) VALUES (:u, :p, :f, 3, true)", [
    ':u' => $uname,
    ':p' => password_hash(md5('Admin@123'), PASSWORD_BCRYPT),
    ':f' => "dr. Concurrency $sfx",
])->execute();
$docUserId = (int)$db->getLastInsertID();
$docToken = \\app\\components\\JwtHelper::generateToken(['uid' => $docUserId, 'role' => 'DOCTOR']);

$adminId = (int)$db->createCommand("SELECT id FROM users WHERE roles = 1 AND is_active = true AND deleted_at IS NULL LIMIT 1")->queryScalar();
$adminToken = \\app\\components\\JwtHelper::generateToken(['uid' => $adminId, 'role' => 'ADMIN']);

$db->createCommand("INSERT INTO icd10 (code, name) VALUES ('J00', 'Nasofaringitis akut'), ('R50.9', 'Demam') ON CONFLICT (code) DO NOTHING")->execute();

echo json_encode([
    'sfx' => $sfx,
    'today' => $today,
    'dayToday' => $dayToday,
    'docUserId' => $docUserId,
    'docToken' => $docToken,
    'adminToken' => $adminToken,
]);
`;
  const setup = await runPhpCode(setupScript);
  const { sfx, today, dayToday, docUserId, docToken, adminToken } = setup;

  const poliRes = await runPhpCode(buildPhpApiScript('POST', '/api/polis', { code: `CX_${sfx}`, name: `Poli CX ${sfx}`, registration_fee: 35000 }, adminToken));
  const docRes = await runPhpCode(buildPhpApiScript('POST', '/api/doctors', { user_id: docUserId, str_no: `STRCX_${sfx}`, full_name: `dr. CX ${sfx}` }, adminToken));
  await runPhpCode(buildPhpApiScript('POST', '/api/schedules', { doctor_id: docRes.data.id, poli_id: poliRes.data.id, day_of_week: dayToday, start_time: '00:00', end_time: '23:59', quota: 10 }, adminToken));
  const patRes = await runPhpCode(buildPhpApiScript('POST', '/api/patients', { full_name: `Pasien CX ${sfx}`, gender: 'L', birth_date: '1990-05-10' }, adminToken));
  const visitRes = await runPhpCode(buildPhpApiScript('POST', '/api/visits', { patient_id: patRes.data.id, poli_id: poliRes.data.id, doctor_id: docRes.data.id, visit_date: today }, adminToken));
  const visitId = visitRes.data.id;

  console.log(`Setup Visit ID: ${visitId}, Doctor ID: ${docRes.data.id}\n`);

  // ---------------- CX-1: 5 Concurrent POST /api/visits/{id}/examination/start ----------------
  console.log('--- CX-1: 5 Concurrent POST /api/visits/' + visitId + '/examination/start ---');
  const barrier1 = path.join(os.tmpdir(), `cx1_barrier_${Date.now()}.flag`);
  const cx1Promises = [];
  for (let i = 1; i <= 5; i++) {
    cx1Promises.push(runPhpCode(buildPhpApiScript('POST', `/api/visits/${visitId}/examination/start`, {}, docToken, barrier1)));
  }
  await new Promise((r) => setTimeout(r, 450));
  fs.writeFileSync(barrier1, 'GO');
  const cx1Results = await Promise.all(cx1Promises);
  try { fs.unlinkSync(barrier1); } catch (_) {}

  const examIds = cx1Results.map((r, i) => {
    console.log(`  Worker #${i + 1} -> rc=${r.rc}, examination_id=${r.data ? r.data.id : 'null'}, status=${r.data ? r.data.status : 'null'}`);
    return r.data ? r.data.id : null;
  });
  const uniqueExamIds = [...new Set(examIds.filter(Boolean))];
  const examId = uniqueExamIds[0];

  // Prepare & Finalize Examination v1 for CX-2
  await runPhpCode(buildPhpApiScript('PUT', `/api/examinations/${examId}`, {
    complaint: 'Demam akut 2 hari',
    vital_sign: { systolic: 120, diastolic: 80, temperature: 38.2 },
    diagnoses: [{ icd10_code: 'J00', type: 'PRIMARY', notes: 'Diagnosis awal v1' }],
  }, docToken));
  await runPhpCode(buildPhpApiScript('POST', `/api/examinations/${examId}/finalize`, {}, docToken));

  // ---------------- CX-2: 2 Concurrent POST /api/examinations/{id}/revise (version=1) ----------------
  console.log(`\n--- CX-2: 2 Concurrent POST /api/examinations/${examId}/revise (version=1) ---`);
  const barrier2 = path.join(os.tmpdir(), `cx2_barrier_${Date.now()}.flag`);
  const cx2Promises = [
    runPhpCode(buildPhpApiScript('POST', `/api/examinations/${examId}/revise`, {
      version: 1,
      reason: 'Revisi konkuren pertama oleh dokter pemeriksa',
      complaint: 'Demam akut 2 hari (Revisi Worker 1)',
      diagnoses: [
        { icd10_code: 'J00', type: 'PRIMARY' },
        { icd10_code: 'R50.9', type: 'SECONDARY' },
      ],
    }, docToken, barrier2)),
    runPhpCode(buildPhpApiScript('POST', `/api/examinations/${examId}/revise`, {
      version: 1,
      reason: 'Revisi konkuren kedua dengan version=1 yang sama',
      complaint: 'Demam akut 2 hari (Revisi Worker 2)',
      diagnoses: [{ icd10_code: 'R50.9', type: 'PRIMARY' }],
    }, docToken, barrier2)),
  ];
  await new Promise((r) => setTimeout(r, 450));
  fs.writeFileSync(barrier2, 'GO');
  const cx2Results = await Promise.all(cx2Promises);
  try { fs.unlinkSync(barrier2); } catch (_) {}

  let cx2Success = 0;
  let cx2Conflict = 0;
  cx2Results.forEach((r, i) => {
    console.log(`  Worker #${i + 1} -> rc=${r.rc}, rm="${r.rm}", version=${r.data ? r.data.version : '-'}`);
    if (r.rc === 200) cx2Success++;
    if (r.rc === 409) cx2Conflict++;
  });

  const dbCheck = await runPhpCode(`<?php
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');
require '${PROJECT_ROOT}/config/dotenv.php';
require '${PROJECT_ROOT}/vendor/autoload.php';
require '${PROJECT_ROOT}/vendor/yiisoft/yii2/Yii.php';
$config = require '${PROJECT_ROOT}/config/web.php';
new yii\\web\\Application($config);
$db = Yii::$app->db;
echo json_encode([
    'examCountForVisit' => (int)$db->createCommand("SELECT COUNT(*) FROM examinations WHERE visit_id = :v", [':v' => ${visitId}])->queryScalar(),
    'finalVersion' => (int)$db->createCommand("SELECT version FROM examinations WHERE id = :id", [':id' => ${examId}])->queryScalar(),
    'revisionCount' => (int)$db->createCommand("SELECT COUNT(*) FROM examination_revisions WHERE examination_id = :id", [':id' => ${examId}])->queryScalar(),
]);
`);

  console.log('\n--- DB VERIFICATION ---');
  console.log(`CX-1: examinations rows for visit ${visitId} = ${dbCheck.examCountForVisit} (expected: 1), unique examination_id across 5 responses = ${uniqueExamIds.length} (id=${examId})`);
  console.log(`CX-2: 200 count = ${cx2Success} (expected: 1), 409 count = ${cx2Conflict} (expected: 1), final version = ${dbCheck.finalVersion} (expected: 2), examination_revisions count = ${dbCheck.revisionCount} (expected: 1)`);

  const cx1Pass = dbCheck.examCountForVisit === 1 && uniqueExamIds.length === 1 && cx1Results.every((r) => r.rc === 200);
  const cx2Pass = cx2Success === 1 && cx2Conflict === 1 && dbCheck.finalVersion === 2 && dbCheck.revisionCount === 1;

  console.log(`\n[RESULT] CX-1: ${cx1Pass ? 'PASS' : 'FAIL'}`);
  console.log(`[RESULT] CX-2: ${cx2Pass ? 'PASS' : 'FAIL'}`);
  process.exit(cx1Pass && cx2Pass ? 0 : 1);
}

main();
