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
    button { background: #6e1423; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-size: 16px; cursor: pointer; }
    button:hover { background: #4a0c17; }
</style>
</head>
<body>
    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1>CareerPath AI</h1>
    <p class="intro">Answer each statement honestly based on how much it describes you.
    Scale: 1 = Strongly Disagree, 2 = Disagree, 3 = Agree, 4 = Strongly Agree.</p>

    <form action="submit.php" method="POST">
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
            <legend>Your Skills</legend>
            <p style="font-size:13px;color:#555;margin-top:0;">List skills you already have, separated by commas — e.g. "computer basics, public speaking, first aid, basic coding". CareerPath AI will show which required skills you already meet for each recommended career, and which ones to work on.</p>
            <textarea name="skills" rows="3" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;box-sizing:border-box;" placeholder="e.g. Microsoft Excel, teamwork, basic coding, customer service"></textarea>
        </fieldset>

        <fieldset>
            <legend>Academic Background</legend>
            <p style="font-size:13px;color:#555;margin-top:0;">General academic average (0–100). This is required so your recommendations can factor in your academic standing.</p>
            <input type="number" name="academic_average" min="0" max="100" step="0.01" required style="width:140px;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;" placeholder="e.g. 88.5">
        </fieldset>

        <button type="submit">Get My Career Recommendations</button>
    </form>
</body>
</html>
