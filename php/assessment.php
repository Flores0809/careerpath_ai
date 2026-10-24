<?php
// Mirrors the paper's evaluation scale style for consistency.

require __DIR__ . '/student_auth.php';
require_once __DIR__ . '/riasec_helper.php';
$currentStudent = require_student_login();

$pdo = get_db();
$categoryNames = $pdo->query(
    "SELECT DISTINCT career_category FROM careers WHERE status = 'active' AND career_category IS NOT NULL AND career_category <> '' ORDER BY career_category"
)->fetchAll(PDO::FETCH_COLUMN);

$questions = [
    'R' => [
        'I enjoy hands-on tasks that involve using tools, power equipment, or machinery.',
        'I enjoy building or repairing everyday items, like furniture, gadgets, or small machines, with my hands.',
        'I prefer outdoor or physical activities over sitting at a desk.',
        'I enjoy participating in mechanical or electrical projects.',
        'I enjoy driving or operating trucks, heavy equipment, or machinery.',
        'I enjoy doing hands-on, physically demanding work to help solve urgent, real-world problems.',
        'I enjoy raising animals or growing crops/plants.',
        'I enjoy inspecting or testing products and parts to make sure they work properly.',
        'I enjoy construction work, like laying bricks or tiles, installing fixtures, or maintaining equipment.',
        'I enjoy putting together electronic parts, like circuits, wiring, or small devices.',
    ],
    'I' => [
        'I enjoy solving hard problems or puzzles.',
        'I enjoy doing research or experiments.',
        'I enjoy figuring out how and why things work.',
        'I enjoy looking closely at information to find patterns.',
        'I enjoy creating new medicines or making new science discoveries.',
        'I enjoy studying scientific topics, such as outer space, weather, or diseases.',
        'I enjoy examining the causes of things, like diseases or unusual events.',
        'I enjoy working in a laboratory, like examining samples under a microscope.',
        'I enjoy studying ways to solve environmental problems, like pollution.',
        'I enjoy inventing new or better products or materials.',
    ],
    'A' => [
        'I enjoy creative activities like arts and crafts, writing, or music.',
        'I enjoy coming up with original ideas or designs.',
        'I prefer flexible tasks over strict routines.',
        'I enjoy expressing myself through art, media, or performance.',
        'I enjoy writing stories, plays, or scripts.',
        'I enjoy performing in front of others, such as singing, dancing, or acting.',
        'I enjoy creating visual art or effects, like drawing, painting, or filmmaking.',
        'I enjoy playing a musical instrument or composing music.',
        'I enjoy editing videos, photos, or movies.',
        'I enjoy designing or decorating things, like sets, costumes, posters, or event decorations.',
    ],
    'S' => [
        'I enjoy helping or caring for other people.',
        'I enjoy working in teams and collaborating with others.',
        'I enjoy listening to and supporting people through their problems.',
        'I enjoy volunteering or community-oriented activities.',
        'I enjoy teaching or tutoring others, like classmates or younger students.',
        'I enjoy taking care of children, the elderly, or people who need assistance.',
        'I enjoy giving advice or guidance to help someone make a decision.',
        'I enjoy teaching or helping people with special needs, like students who are deaf or have a disability.',
        'I enjoy helping people stay healthy or recover, like through therapy, exercise routines, or health advice.',
        'I enjoy coaching others in a sport or physical activity, or teaching them a new skill.',
    ],
    'E' => [
        'I enjoy leading a group or convincing others to see my point of view.',
        'I enjoy taking initiative and starting new projects.',
        'I enjoy taking risks to achieve a goal.',
        'I enjoy selling, promoting, or negotiating.',
        'I enjoy being in charge of a group of people or a business.',
        'I am interested in starting and running my own business someday.',
        'I enjoy marketing or advertising a product or service.',
        'I enjoy speaking up for others or defending a person\'s rights, like in a debate or court case.',
        'I enjoy making business decisions about buying and selling, like products or investments.',
        'I enjoy running or managing a store, salon, or shop.',
    ],
    'C' => [
        'I enjoy organizing information, files, or schedules.',
        'I enjoy following clear plans, procedures, and instructions.',
        'I prefer being very careful and getting things exactly right, instead of guessing.',
        'I enjoy working with numbers, records, or spreadsheets.',
        'I enjoy keeping accurate records, like inventories, receipts, or attendance.',
        'I enjoy double-checking documents or data for errors before submitting them.',
        'I enjoy handling routine office tasks, like filing, sorting mail, or data entry.',
        'I enjoy setting up and maintaining computer systems that keep an organization\'s data organized, like databases or file systems.',
        'I enjoy calculating wages, payments, or budgets accurately.',
        'I enjoy processing orders, deliveries, and other administrative paperwork.',
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — Student Intake</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .intro, body > form, body > .info-details { max-width: 760px; margin-left: auto; margin-right: auto; }
    body > .info-details { box-sizing: border-box; }
    h1 { color: #6e1423; }
    .intro { color: #555; margin-bottom: 30px; }
    fieldset { background: #f5f5f5; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 22px; padding: 16px 20px; }
    legend { font-weight: bold; color: #6e1423; padding: 0 6px; }
    .question { margin: 14px 0; }
    .question p { margin: 0 0 6px 0; }
    .scale { display: flex; gap: 40px; font-size: 15.5px; }
    .scale label { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
    .scale input[type="radio"] { width: 18px; height: 18px; }
    button { background: #6e1423; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-size: 17.5px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    button:hover { background: #4a0c17; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }

    @media (max-width: 600px) {
        .scale { gap: 6px; }
        .scale label { flex: 1; justify-content: center; padding: 10px 4px; min-height: 44px; border: 1px solid #ddd; border-radius: 8px; background: #fff; box-sizing: border-box; }
        .scale input[type="radio"] { width: 20px; height: 20px; margin: 0; }
    }
.info-details { background: #f9f3f4; border: 1px solid #ecd5d9; border-radius: 8px; padding: 10px 14px; margin: 10px 0; font-size: 14.5px; line-height: 1.5; }
    .info-details summary { cursor: pointer; font-weight: bold; color: #6e1423; }
    .info-details ul { margin: 8px 0 0; padding-left: 20px; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1>CareerPath AI</h1>
    <p class="intro">Answer each statement honestly based on how much it describes you.
    Scale: 1 = Strongly Disagree, 2 = Disagree, 3 = Agree, 4 = Strongly Agree.</p>
    <?php render_about_assessment(); render_riasec_guide(); ?>

    <form action="submit.php" method="POST" id="assessment-form">
        <fieldset>
            <legend>🎯 Industry You Want to Work In</legend>
            <p style="font-size:14.5px;color:#555;margin-top:0;">Choose the industry you want to work in. Your results will show the career in that industry that fits you best <em>next to</em> the career your RIASEC answers fit best overall, so you can compare what the system found with what you are aiming for. Not sure? Pick the industry that interests you most.</p>
            <label style="display:block;font-size:14.5px;font-weight:bold;margin:10px 0 4px;">Field / Industry</label>
            <select name="dream_category" id="dream_cluster" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;box-sizing:border-box;">
                <option value="">— Select an industry —</option>
                <?php foreach ($categoryNames as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
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
            <legend>Your Skills</legend>
            <p style="font-size:14.5px;color:#555;margin-top:0;">List skills you already have, separated by commas (e.g. computer basics, public speaking, first aid). This helps show which required skills you already meet. Not sure? Type "N/A".</p>
            <textarea name="skills" rows="3" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;box-sizing:border-box;" placeholder="e.g. Microsoft Excel, teamwork, basic coding, customer service"></textarea>
        </fieldset>

        <fieldset>
            <legend>Academic Average</legend>
            <p style="font-size:14.5px;color:#555;margin-top:0;">Your general/overall academic average (0–100). This is required so your recommendations can factor in your academic standing.</p>
            <input type="number" name="academic_average" min="0" max="100" step="0.01" required style="width:140px;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;" placeholder="e.g. 88.5">
        </fieldset>

        <button type="submit">Get My Career Recommendations</button>
    </form>

    <script>
        // Keep the student on this page (with every answer intact) when the
        // skills box is empty or only spaces: the browser's own "required"
        // check ignores whitespace, so it is re-checked here.
        document.getElementById('assessment-form').addEventListener('submit', function (e) {
            var skills = this.elements['skills'];
            if (skills && skills.value.trim() === '') {
                e.preventDefault();
                skills.value = '';
                skills.setCustomValidity('Please list your skills, or type "N/A" if you are not sure.');
                skills.reportValidity();
                skills.addEventListener('input', function () { skills.setCustomValidity(''); }, { once: true });
            }
        });
    </script>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
