<?php
// CareerPath AI - shared wording helpers (RIASEC meanings, data-source labels,
// rule-based "why we recommend this" text). All plain PHP, no AI calls, so the
// explanations are deterministic and cost nothing.

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

/**
 * Brief, rule-based reason a career was recommended, built from the two
 * RIASEC dimensions that contributed most to the match (see
 * build_career_match_data) — no AI involved.
 */
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
       . '<p><strong>Questions:</strong> 7 statements for each of the 6 types (42 in all). Rate each from 1 (Strongly Disagree) to 4 (Strongly Agree). Each type scores out of 28 points (7 × 4).</p>'
       . '<p><strong>Scoring:</strong> Your answers for each type are added up and compared with the interest profile stored for each career. '
       . 'The closer the shape of your profile is to a career\'s profile, the higher its match score, and careers are ranked from best to lowest match. '
       . 'A match score shows how closely your interests resemble a career\'s profile — it is guidance, not a guarantee of success or a decision for you.</p>'
       . '</details>';
}
