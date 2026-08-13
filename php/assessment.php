<?php
// CareerPath AI - Barebone Student Intake (RIASEC self-assessment)
// 24 statements, 4 per RIASEC type, 4-point Likert scale (1 = Strongly Disagree, 4 = Strongly Agree)
// Mirrors the paper's evaluation scale style for consistency.
//
// Requires a logged-in student account so results/history can be saved
// (see php/student_auth.php, php/student_register.php, php/student_login.php).
// The front landing page (index.php) links here for students who choose
// "Take the Assessment".

require __DIR__ . '/student_auth.php';
$currentStudent = require_student_login();

// Dream-career picker (cluster -> specific job) shown before the RIASEC
// questions. Goal: give the results page something to anchor on besides an
// undifferentiated top-N list — e.g. Chef and Architect can both land at a
// similar match % but are completely different fields; asking the student
// what they're actually aiming for lets submit.php highlight that specific
// career's fit first, with everything else tucked into an "explore other
// careers" section instead of all being dumped together with equal weight.
// Only categorized careers are eligible to appear here — no "Other" catch-all.
// Every career should belong to a real, specific industry cluster (set on
// php/careers.php when approving, or php/careers_manage.php for existing
// ones); an uncategorized career just doesn't show up as a dream-career
// option yet, rather than getting dumped into a vague bucket.
$pdo = get_db();
$careerRows = $pdo->query(
    "SELECT career_id, career_title, career_category FROM careers WHERE status = 'active' AND career_category IS NOT NULL ORDER BY career_category, career_title"
)->fetchAll();
$careersByCategory = [];
foreach ($careerRows as $row) {
    $careersByCategory[$row['career_category']][] = ['id' => (int) $row['career_id'], 'title' => $row['career_title']];
}

$questions = [
    'R' => [
        'I enjoy working with tools, machines, or equipment.',
        'I like building or fixing things with my hands.',
        'I prefer outdoor or physical activities over sitting at a desk.',
        'I am comfortable operating or repairing mechanical/electrical systems.',
    ],
    'I' => [
        'I enjoy solving complex problems or puzzles.',
        'I like conducting research or running experiments.',
        'I am curious about how and why things work.',
        'I enjoy analyzing data or information to find patterns.',
    ],
    'A' => [
        'I enjoy creative activities like drawing, writing, or music.',
        'I like coming up with original ideas or designs.',
        'I prefer flexible, unstructured tasks over strict routines.',
        'I enjoy expressing myself through art, media, or performance.',
    ],
    'S' => [
        'I enjoy helping, teaching, or caring for other people.',
        'I like working in teams and collaborating with others.',
        'I am comfortable listening to and supporting people\'s problems.',
        'I enjoy volunteering or community-oriented activities.',
    ],
    'E' => [
        'I enjoy leading a group or convincing others to see my point of view.',
        'I like taking initiative and starting new projects.',
        'I am comfortable taking risks to achieve a goal.',
        'I enjoy selling, promoting, or negotiating.',
    ],
    'C' => [
        'I enjoy organizing information, files, or schedules.',
        'I like following clear rules, procedures, and instructions.',
        'I am detail-oriented and prefer accuracy over improvisation.',
        'I enjoy working with numbers, records, or spreadsheets.',
    ],
];

$typeLabels = [
    'R' => 'Realistic',
    'I' => 'Investigative',
    'A' => 'Artistic',
    'S' => 'Social',
    'E' => 'Enterprising',
    'C' => 'Conventional',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Student Intake</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .intro, body > form { max-width: 760px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .intro { color: #555; margin-bottom: 30px; }
    fieldset { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 22px; padding: 16px 20px; }
    legend { font-weight: bold; color: #6e1423; padding: 0 6px; }
    .question { margin: 14px 0; }
    .question p { margin: 0 0 6px 0; }
    .scale { display: flex; gap: 18px; font-size: 14px; }
    .scale label { display: flex; align-items: center; gap: 4px; }
    button { background: #6e1423; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-size: 16px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    button:hover { background: #4a0c17; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1>CareerPath AI</h1>
    <p class="intro">Answer each statement honestly based on how much it describes you.
    Scale: 1 = Strongly Disagree, 2 = Disagree, 3 = Agree, 4 = Strongly Agree.</p>

    <form action="submit.php" method="POST" id="assessment-form">
        <fieldset>
            <legend>🎯 Your Dream Career</legend>
            <p style="font-size:13px;color:#555;margin-top:0;">Before we get into the assessment — what career are you aiming for? Pick the field it falls under, then the specific career. We'll show you exactly how well your RIASEC profile fits <em>that</em> career, plus other careers you might not have considered.</p>
            <label style="display:block;font-size:13px;font-weight:bold;margin:10px 0 4px;">Field / Industry</label>
            <select id="dream_cluster" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;box-sizing:border-box;">
                <option value="">— Select a field —</option>
                <?php foreach (array_keys($careersByCategory) as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <label style="display:block;font-size:13px;font-weight:bold;margin:10px 0 4px;">Specific Career</label>
            <select name="dream_career_id" id="dream_career_id" required disabled style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;box-sizing:border-box;">
                <option value="">— Select a field first —</option>
            </select>
        </fieldset>

        <?php foreach ($questions as $type => $items): ?>
            <fieldset>
                <legend><?= htmlspecialchars($typeLabels[$type]) ?> (<?= $type ?>)</legend>
                <?php foreach ($items as $i => $text): ?>
                    <div class="question">
                        <p><?= htmlspecialchars($text) ?></p>
                        <div class="scale">
                            <?php for ($v = 1; $v <= 4; $v++): ?>
                                <label>
                                    <input type="radio" name="<?= $type ?>[<?= $i ?>]" value="<?= $v ?>" required>
                                    <?= $v ?>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>

        <fieldset>
            <legend>Your Skills (optional, but recommended)</legend>
            <p style="font-size:13px;color:#555;margin-top:0;">List skills you already have, separated by commas — e.g. "computer basics, public speaking, first aid, basic coding". CareerPath AI will show which required skills you already meet for each recommended career, and which ones to work on.</p>
            <textarea name="skills" rows="3" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;box-sizing:border-box;" placeholder="e.g. Microsoft Excel, teamwork, basic coding, customer service"></textarea>
        </fieldset>

        <fieldset>
            <legend>Academic Background</legend>
            <p style="font-size:13px;color:#555;margin-top:0;">Your general/overall academic average (0–100). This is required so your recommendations can factor in your academic standing.</p>
            <input type="number" name="academic_average" min="0" max="100" step="0.01" required style="width:140px;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;" placeholder="e.g. 88.5">
        </fieldset>

        <button type="submit">Get My Career Recommendations</button>
    </form>

    <script>
        var careersByCategory = <?= json_encode($careersByCategory) ?>;
        var clusterSelect = document.getElementById('dream_cluster');
        var careerSelect = document.getElementById('dream_career_id');

        clusterSelect.addEventListener('change', function () {
            var careers = careersByCategory[clusterSelect.value] || [];
            careerSelect.innerHTML = '';
            if (!careers.length) {
                careerSelect.innerHTML = '<option value="">— Select a field first —</option>';
                careerSelect.disabled = true;
                return;
            }
            careerSelect.disabled = false;
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '— Select a career —';
            careerSelect.appendChild(placeholder);
            careers.forEach(function (c) {
                var opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.title;
                careerSelect.appendChild(opt);
            });
        });
    </script>
</body>
</html>
