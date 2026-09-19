<?php
// CareerPath AI - Built-in FAQ chatbot knowledge base
// --------------------------------------------------------------------
// Deliberately NOT an LLM call — no API key, no external service, no
// internet dependency. Each entry is a canned question/answer pair with
// a list of trigger keywords. chatbot_ask.php scores the visitor's typed
// message against these keyword lists (simple overlap counting, the same
// "hand-roll it in PHP since there's no scikit-learn here" approach the
// rest of this app already uses for RIASEC matching) and returns the
// best-matching answer. Fully deterministic and easy to explain/defend:
// there's no black box, just a lookup table.
//
// To add a new FAQ entry: append another array with 'keywords' (words a
// student/staff member might actually type — include common misspellings
// or synonyms), 'question' (shown as the canned label), and 'answer'.

return [
    [
        'keywords' => ['what', 'careerpath', 'system', 'about', 'is', 'work', 'works', 'function', 'purpose', 'use'],
        'question' => 'What is CareerPath AI?',
        'answer' => "CareerPath AI is Meridian Educational Institution's JHS/SHS career guidance system. Students take a RIASEC personality assessment, add their skills and academic average, and get career recommendations from a hybrid matching engine plus AI-generated insights. Counselors and admins review, edit, and approve the careers those recommendations are built from.",
    ],
    [
        'keywords' => ['riasec', 'mean', 'stand', 'letters', 'holland'],
        'question' => 'What is RIASEC?',
        'answer' => "RIASEC is a career-personality model (also called the Holland Code) with six types: Realistic, Investigative, Artistic, Social, Enterprising, and Conventional. The assessment scores you 0–100 on each type, and your results are matched against the RIASEC profile of every career in the system.",
    ],
    [
        'keywords' => ['take', 'assessment', 'start', 'quiz', 'test'],
        'question' => 'How do I take the assessment?',
        'answer' => "Log in as a student, then go to Dashboard → Take the Assessment. You'll answer RIASEC personality questions, list your skills, enter your academic average, and optionally pick a dream career — then submit to see your matches.",
    ],
    [
        'keywords' => ['recommend', 'recommendation', 'match', 'matching', 'computed', 'calculate', 'algorithm'],
        'question' => 'How are career recommendations generated?',
        'answer' => "Your RIASEC scores are compared to each career's RIASEC profile using mean-centered cosine similarity (the same math as a Pearson correlation) — this finds which careers' \"shape\" of interests best fits yours, not just which type you scored highest in. The top matches are ranked and shown with an explanation of which traits contributed most.",
    ],
    [
        'keywords' => ['ai', 'gemini', 'insight', 'generated', 'commentary'],
        'question' => 'What do the AI-generated insights do?',
        'answer' => "Once your matches are computed by the RIASEC matching engine, an AI (Gemini) writes a short, personalized explanation of why a career fits you and what it's like day-to-day. The matching itself is regular math — the AI only writes the explanation on top of it.",
    ],
    [
        'keywords' => ['dream', 'career', 'goal', 'choose', 'pick'],
        'question' => 'What is a "dream career"?',
        'answer' => "It's a career you personally aspire to, which you can pick during the assessment. CareerPath AI shows how well it currently fits your RIASEC profile and highlights the gap between your traits and what that career typically needs.",
    ],
    [
        'keywords' => ['local', 'international', 'scope', 'difference', 'abroad'],
        'question' => "What's the difference between local and international careers?",
        'answer' => "Careers are tagged by scope: \"local\" (Philippines-based, e.g. sourced from PhilJobNet or Kalibrr) or \"international\" (e.g. RemoteOK). This just tells you where that kind of job posting was found — the RIASEC matching works the same way for both.",
    ],
    [
        'keywords' => ['consultation', 'counselor', 'talk', 'meet', 'appointment', 'schedule'],
        'question' => 'How do I request a consultation?',
        'answer' => "Go to Consultations in your student nav and submit a request — a counselor will review it and follow up. You can check the status of your request on the same page.",
    ],
    [
        'keywords' => ['register', 'signup', 'create', 'account', 'code'],
        'question' => 'How do I create a student account?',
        'answer' => "From the home page, choose Student → Create an Account. You'll need your LRN (12 digits), grade level, and the access code your school provided — this keeps registration limited to actual MEII students.",
    ],
    [
        'keywords' => ['password', 'forgot', 'reset', 'login', 'locked'],
        'question' => 'I forgot my password — what do I do?',
        'answer' => "There's no self-service reset yet — ask your counselor or system administrator to reset it for you from Manage Accounts (staff) or your student profile (students can change their own password once logged in, under My Profile).",
    ],
    [
        'keywords' => ['profile', 'edit', 'update', 'information', 'details'],
        'question' => 'How do I update my profile?',
        'answer' => "Students: go to My Profile to update your details or password. Staff: go to your name in the top-right of the nav bar to reach Staff Profile.",
    ],
    [
        'keywords' => ['history', 'past', 'previous', 'results', 'again'],
        'question' => 'Where can I see my past assessment results?',
        'answer' => "Go to Assessment → My History as a student. Every past submission is listed there with its recommendations and AI commentary.",
    ],
    [
        'keywords' => ['crawler', 'scrape', 'source', 'philjobnet', 'kalibrr', 'remoteok', 'pending'],
        'question' => 'How does the web crawler add new careers?',
        'answer' => "Staff can run the crawler from Career Review Queue — it pulls postings from PhilJobNet, Kalibrr, RemoteOK, and other sources, auto-enriches them with AI where possible, and stages them as \"pending\" for a counselor to review and approve before they appear to students.",
    ],
    [
        'keywords' => ['approve', 'reject', 'pending', 'review', 'queue'],
        'question' => 'How do I approve or reject a pending career?',
        'answer' => "As a counselor or admin, open Career Review Queue. Each pending card shows the raw scraped data next to an editable form pre-filled by AI — adjust anything needed, then Approve into the live catalog or Reject.",
    ],
    [
        'keywords' => ['duplicate', 'already', 'exists'],
        'question' => 'What does the "possible duplicate" warning mean?',
        'answer' => "It means a pending entry's title closely matches a career already approved in the catalog. Compare the two side by side before deciding — a lower match percentage often just means a similar-sounding but distinct specialization, not a true duplicate.",
    ],
    [
        'keywords' => ['skills', 'required', 'needed'],
        'question' => 'Where do required skills for a career come from?',
        'answer' => "They're suggested by AI during enrichment, but a counselor can edit, remove, or add skills before approving a career, and mark which ones are required.",
    ],
    [
        'keywords' => ['notification', 'notify', 'alert', 'badge'],
        'question' => 'What are notifications for?',
        'answer' => "You'll get a notification for things like counselor notes on your profile (students) or new pending careers and consultation requests (staff). The number badge in the nav shows how many are unread.",
    ],
    [
        'keywords' => ['help', 'contact', 'support', 'human', 'staff', 'stuck'],
        'question' => 'This chatbot can\'t answer my question — who do I ask?',
        'answer' => "For anything account- or school-specific, reach out to your school counselor directly, or use Request Consultation if you're a student. This chatbot only knows how to explain the CareerPath AI system itself.",
    ],
];
