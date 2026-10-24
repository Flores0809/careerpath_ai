<?php
// CareerPath AI - shared wording helpers (RIASEC meanings, data-source labels,
// rule-based "why we recommend this" text). All plain PHP, no AI calls, so the

/** What each RIASEC (Holland) type means, in student-friendly words. */
function riasec_type_info(): array
{
    return [
        'R' => ['name' => 'Realistic',     'blurb' => 'Hands-on and practical: you like working with tools, machines, plants, animals or the outdoors.'],
        'I' => ['name' => 'Investigative', 'blurb' => 'Curious and analytical: you like researching, solving problems and understanding how things work.'],
        'A' => ['name' => 'Artistic',      'blurb' => 'Creative and expressive: you like designing, writing, performing or coming up with original ideas.'],
        'S' => ['name' => 'Social',        'blurb' => 'Helpful and people-oriented: you like teaching, caring for, guiding or working with others.'],
        'E' => ['name' => 'Enterprising',  'blurb' => 'Persuasive and goal-driven: you like leading, selling, organizing projects and taking initiative.'],
        'C' => ['name' => 'Conventional',  'blurb' => 'Organized and detail-oriented: you like clear procedures, records, numbers and keeping things in order.'],
    ];
}

/** Friendly name of where a career's information came from. */
function career_source_label(?string $source): string
{
    $map = [
        'philjobnet' => 'PhilJobNet (Philippine job listings)',
        'onet'       => 'O*NET (U.S. Department of Labor occupational database)',
        'adzuna'     => 'Adzuna job listings',
        'remoteok'   => 'RemoteOK job listings',
        'kalibrr'    => 'Kalibrr job listings',
        'crawler'    => 'Web crawler (PhilJobNet / O*NET job data), reviewed by staff',
        'seed'       => 'Curated by the system administrators',
        'manual'     => 'Entered by a counselor/administrator',
    ];
    $key = strtolower(trim((string) $source));
    return $map[$key] ?? ($key !== '' ? ucfirst($key) : $map['seed']);
}

/** "Oct 14, 2026" or null when the column is missing/empty. */
function career_updated_label(?string $updatedAt): ?string
{
    if (!$updatedAt) {
        return null;
    }
    $ts = strtotime($updatedAt);
    return $ts ? date('M j, Y', $ts) : null;
}

/** Prominent HTML badge row: source + last-updated date (already escaped). */
function career_source_badges(array $careerRow): string
{
    $html = '<span class="src-badge">📌 Source: ' . htmlspecialchars(career_source_label($careerRow['source'] ?? null)) . '</span>';
    $date = career_updated_label($careerRow['updated_at'] ?? null);
    if ($date) {
        $html .= '<span class="upd-badge">🕒 Last updated: ' . htmlspecialchars($date) . '</span>';
    }
    return $html;
}

/** One-line "Source: X · Last updated: Y" for a careers row. */
function career_source_line(array $careerRow): string
{
    $line = 'Source: ' . career_source_label($careerRow['source'] ?? null);
    $date = career_updated_label($careerRow['updated_at'] ?? null);
    if ($date) {
        $line .= ' · Last updated: ' . $date;
    }
    return $line;
}

function recommendation_reason(array $career): string
{
    $info = riasec_type_info();
    $dims = $career['top_dimensions'] ?? [];
    if (!$dims) {
        return '';
    }
    $names = [];
    foreach ($dims as $d) {
        $names[] = strtolower($info[$d['type']]['name'] ?? $d['type']);
    }
    $title = $career['career_title'] ?? 'this career';
    $text = 'We recommend ' . $title . ' because your ' . implode(' and ', $names)
          . ' interests line up with what this career asks for.';
    $first = $info[$dims[0]['type']]['blurb'] ?? '';
    if ($first !== '') {
        $text .= ' (' . $info[$dims[0]['type']]['name'] . ': ' . lcfirst($first) . ')';
    }
    return $text;
}

/** Collapsible "What do the six types mean?" guide (echoes HTML). */
function render_riasec_guide(): void
{
    echo '<details class="info-details"><summary>What do the six RIASEC types mean?</summary><ul>';
    foreach (riasec_type_info() as $letter => $t) {
        echo '<li><strong>' . htmlspecialchars($t['name']) . ' (' . $letter . ')</strong> — ' . htmlspecialchars($t['blurb']) . '</li>';
    }
    echo '</ul></details>';
}

/** Collapsible "About this assessment" box: basis, items, scale and scoring (echoes HTML). */
function render_about_assessment(): void
{
    echo '<details class="info-details"><summary>About this assessment — how it works</summary>'
       . '<p><strong>Basis:</strong> The questions follow Holland\'s RIASEC interest model, the same framework used by the U.S. Department of Labor\'s O*NET Interest Profiler. '
       . 'It measures how much you are drawn to six kinds of activities: Realistic, Investigative, Artistic, Social, Enterprising and Conventional.</p>'
       . '<p><strong>Questions:</strong> 10 statements for each of the 6 types (60 in all). Rate each from 1 (Strongly Disagree) to 4 (Strongly Agree). Each type scores out of 40 points (10 × 4).</p>'
       . '<p><strong>Scoring:</strong> Your answers for each type are added up and compared with the interest profile stored for each career. '
       . 'The closer the shape of your profile is to a career\'s profile, the higher its match score, and careers are ranked from best to lowest match. '
       . 'A match score shows how closely your interests resemble a career\'s profile — it is guidance, not a guarantee of success or a decision for you.</p>'
       . '</details>';
}

/** Match % (0-100) between a student's RIASEC vector and a career's, both as ['R'..'C' => 0-1]. */
function riasec_similarity_pct(array $a, array $b): float
{
    $keys = ['R', 'I', 'A', 'S', 'E', 'C'];
    $meanA = array_sum($a) / count($keys);
    $meanB = array_sum($b) / count($keys);
    $dot = 0.0; $normA = 0.0; $normB = 0.0;
    foreach ($keys as $k) {
        $ca = $a[$k] - $meanA;
        $cb = $b[$k] - $meanB;
        $dot += $ca * $cb;
        $normA += $ca ** 2;
        $normB += $cb ** 2;
    }
    if ($normA == 0 || $normB == 0) {
        return 50.0;
    }
    return (($dot / (sqrt($normA) * sqrt($normB))) + 1) / 2 * 100;
}

/** A careers row's RIASEC scores as a 0-1 vector. */
function career_row_vector(array $row): array
{
    return [
        'R' => $row['r_score'] / 100, 'I' => $row['i_score'] / 100, 'A' => $row['a_score'] / 100,
        'S' => $row['s_score'] / 100, 'E' => $row['e_score'] / 100, 'C' => $row['c_score'] / 100,
    ];
}

function load_dream_career(PDO $pdo, $dreamCareerId, array $studentVec): ?array
{
    $dreamCareerId = (int) $dreamCareerId;
    if ($dreamCareerId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id");
    $stmt->execute(['id' => $dreamCareerId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $score = riasec_similarity_pct($studentVec, career_row_vector($row));

    $rank = 1;
    $total = 0;
    foreach ($pdo->query("SELECT r_score, i_score, a_score, s_score, e_score, c_score FROM careers WHERE status = 'active'") as $other) {
        $total++;
        if (riasec_similarity_pct($studentVec, career_row_vector($other)) > $score + 0.0001) {
            $rank++;
        }
    }
    return ['row' => $row, 'match_score' => round($score, 2), 'rank' => $rank, 'total' => $total];
}

/** One plain-language sentence comparing the system's best match with the dream career. */
function dream_comparison_text(string $bestTitle, float $bestScore, string $dreamTitle, float $dreamScore, int $rank, int $total, bool $sameCareer): string
{
    if ($sameCareer) {
        return 'The closest fit in your chosen industry, ' . $dreamTitle . ', is also the career your RIASEC answers fit best (' . round($bestScore) . '% match).';
    }
    $diff = round($bestScore - $dreamScore);
    $text = 'The closest fit in your chosen industry, ' . $dreamTitle . ', is a ' . round($dreamScore) . '% match for your RIASEC profile';
    $text .= ($total > 0 ? ' and ranks #' . $rank . ' of ' . $total . ' careers in the catalog for you.' : '.');
    if ($diff <= 5) {
        $text .= ' The system\'s best match, ' . $bestTitle . ' (' . round($bestScore) . '%), is very close, so both are worth exploring.';
    } else {
        $text .= ' The system\'s best match, ' . $bestTitle . ' (' . round($bestScore) . '%), fits your answers ' . $diff . ' points better.';
    }
    return $text . ' This is guidance only: interest fit is one factor, and you can also work on the traits that career needs.';
}

function ensure_dream_commentary_column(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM student_profiles LIKE 'ai_dream_commentary'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE student_profiles ADD COLUMN ai_dream_commentary TEXT NULL AFTER ai_career_commentary");
        }
    } catch (Exception $e) {
        // No ALTER rights or table missing: the dream note is simply not stored.
    }
}
