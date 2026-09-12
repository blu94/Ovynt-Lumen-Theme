<?php

namespace Theme\Backend\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Repositories\Asset\AssetRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Theme\Backend\Handlers\ExamContributions;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\ExamCase;
use Theme\Backend\Models\ExamPaper;
use Theme\Backend\Models\PaperAttempt;
use Theme\Backend\Models\Testimonial;
use Theme\Backend\Repositories\EnrolmentRepository;
use Theme\Backend\Support\BuilderPage;

/**
 * One demonstration exam, so every screen this theme ships can be seen with data in it.
 *
 * Not a Laravel seeder — a theme has no `db:seed` hook and no service provider to register one
 * in — but the same idea: run it once against a development database and the catalogue, the
 * exam page, the dashboard and the Enrolments screen all have something to show. Nothing in
 * core ever calls it; it is invoked by hand, from a bootstrapped script inside the container:
 *
 *     php -r "require '/var/www/vendor/autoload.php';
 *             \$app = require '/var/www/bootstrap/app.php';
 *             \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 *             print_r((new Theme\Backend\Seeders\DemoExamSeeder)->run());"
 *
 * **It writes through the theme's real code paths, not around them.** The exam, its papers and
 * cases go through `ExamContributions::save()` — the same handler the product form's Exam tab
 * reaches — and the enrolment through `EnrolmentRepository::create()`, so what it seeds is
 * exactly what an operator could have typed. The one thing it fabricates is a finished sitting
 * of the first paper, because the player that would produce one is not built yet (core item C1);
 * that is written directly so the dashboard has a score to draw.
 *
 * Re-running it is safe. Every step looks for what it already made — the product by slug, a
 * paper or case by title, a page by slug, the enrolment by candidate and exam — and fills in
 * only what is missing.
 */
class DemoExamSeeder
{
    public const PRODUCT_SLUG   = 'frcr-2b-rapid-reporting-mock-1';
    public const CANDIDATE_EMAIL = 'user@ovynt.com';

    /** @return array<string,mixed> what was found and what was made, for the caller to print */
    public function run(): array
    {
        return DB::transaction(function () {
            $product      = $this->product();
            $papers       = $this->papers($product);
            $pages        = $this->pages();
            $sitting      = $this->sitting($product);
            $testimonials = $this->testimonials();

            return [
                'product'      => ['id' => $product->id, 'slug' => $product->slug],
                'papers'       => $papers,
                'pages'        => $pages,
                'candidate'    => $sitting,
                'testimonials' => $testimonials,
            ];
        });
    }

    // ------------------------------------------------------------------
    // The exam is a product
    // ------------------------------------------------------------------

    private function product(): Product
    {
        $existing = Product::query()->where('slug->en', self::PRODUCT_SLUG)->first();

        if ($existing) {
            return $existing;
        }

        return Product::create([
            'title'       => ['en' => 'FRCR 2B Rapid Reporting — Mock 1'],
            'subtitle'    => ['en' => 'Two timed papers of plain-film cases with model answers'],
            'description' => ['en' => 'A full-length rapid reporting mock: two papers of plain-film cases, each against the clock. Report every study, end the paper, then mark yourself against the model answers.'],
            'slug'        => ['en' => self::PRODUCT_SLUG],
            'type'        => 'Product',
            'price'       => 149,
            'status'      => 'active',
            // An exam has nothing to post; with this on, checkout would ask for an address.
            'requires_shipping' => false,
            'tax_class'   => 'standard',
        ]);
    }

    // ------------------------------------------------------------------
    // Papers and cases, through the Exam tab's own handler
    // ------------------------------------------------------------------

    /** @return array<int,array{title:string,cases:int}> */
    private function papers(Product $product): array
    {
        $wanted = $this->paperDefinitions();

        $existingPapers = ExamPaper::query()
            ->where('product_id', $product->id)
            ->with(['cases' => fn ($q) => $q->with('images')])
            ->get();

        $rows = [];

        foreach ($wanted as $paper) {
            $known = $existingPapers->first(
                fn (ExamPaper $p) => $p->getTranslation('title', 'en', false) === $paper['title']
            );

            $caseRows = [];

            foreach ($paper['cases'] as $index => $case) {
                $knownCase = $known?->cases->first(
                    fn (ExamCase $c) => $c->getTranslation('title', 'en', false) === $case['title']
                );

                $assetIds = $knownCase && $knownCase->images->isNotEmpty()
                    ? $knownCase->images->pluck('id')->all()
                    : [$this->placeholderImage($paper['short'], $index + 1, $case['label'])];

                $caseRows[] = [
                    'id'           => $knownCase?->id,
                    'title'        => ['en' => $case['title']],
                    'brief'        => ['en' => $case['brief']],
                    'instruction'  => ['en' => 'Report this study.'],
                    'model_answer' => ['en' => '<p>' . $case['answer'] . '</p>'],
                    'score_max'    => $case['score'],
                    'status'       => 'active',
                    'assets'       => array_map(fn ($id) => ['id' => $id], $assetIds),
                ];
            }

            $rows[] = [
                'id'               => $known?->id,
                'title'            => ['en' => $paper['title']],
                'description'      => ['en' => $paper['description']],
                'duration_minutes' => $paper['minutes'],
                'case_set_version' => 1,
                'status'           => 'active',
                'cases'            => $caseRows,
            ];
        }

        // The same payload shape the product form posts, handed to the same handler. Papers the
        // form no longer lists would be removed; every existing paper is listed here by id, so
        // nothing an operator added by hand is touched.
        foreach ($existingPapers as $p) {
            $listed = collect($rows)->contains(fn ($r) => $r['id'] === $p->id);

            if (! $listed) {
                $rows[] = ['id' => $p->id] + $this->paperAsRow($p);
            }
        }

        (new ExamContributions())->save($product, [
            'is_exam'               => true,
            'access_days'           => 90,
            'ideal_percent'         => 60,
            'exam_duration_minutes' => 70,
            'papers'                => $rows,
        ]);

        return ExamPaper::query()
            ->where('product_id', $product->id)
            ->withCount('cases')
            ->ordered()
            ->get()
            ->map(fn (ExamPaper $p) => [
                'title' => $p->getTranslation('title', 'en', false),
                'cases' => (int) $p->cases_count,
            ])
            ->all();
    }

    /** An existing paper, re-expressed as the row the handler expects, so a re-save keeps it. */
    private function paperAsRow(ExamPaper $paper): array
    {
        return [
            'title'            => $paper->getTranslations('title'),
            'description'      => $paper->getTranslations('description'),
            'duration_minutes' => $paper->duration_minutes,
            'case_set_version' => $paper->case_set_version,
            'status'           => $paper->status,
            // No `cases` key: the handler treats its absence as "leave them alone".
        ];
    }

    /**
     * The content. Plain-film cases with the kind of one-line answer a rapid-reporting paper
     * wants; the images are generated placeholders, not radiographs.
     *
     * @return array<int,array{title:string,short:string,description:string,minutes:int,cases:array<int,array{title:string,label:string,brief:string,answer:string,score:float}>}>
     */
    private function paperDefinitions(): array
    {
        return [
            [
                'title'       => 'Paper 1 — Chest and Abdomen',
                'short'       => 'P1',
                'description' => 'Thirty plain films of the chest and abdomen. Report each study in one or two lines; a normal film is a valid answer.',
                'minutes'     => 35,
                'cases'       => [
                    [
                        'title'  => 'Chest radiograph — 54M, breathless',
                        'label'  => 'PA CHEST',
                        'brief'  => '54-year-old man, three days of breathlessness and right-sided pleuritic chest pain.',
                        'answer' => 'Right lower zone consolidation with a small ipsilateral effusion. No pneumothorax. Heart size normal.',
                        'score'  => 5,
                    ],
                    [
                        'title'  => 'Chest radiograph — 23F, post-central line',
                        'label'  => 'AP CHEST',
                        'brief'  => '23-year-old woman, portable film after right internal jugular line insertion.',
                        'answer' => 'Line tip projects over the SVC. Small right apical pneumothorax, no mediastinal shift. Recommend repeat film.',
                        'score'  => 5,
                    ],
                    [
                        'title'  => 'Abdominal radiograph — 71M, distension',
                        'label'  => 'AXR SUPINE',
                        'brief'  => '71-year-old man, two days of abdominal distension and absolute constipation.',
                        'answer' => 'Dilated small-bowel loops to 4.5 cm with a paucity of colonic gas, in keeping with small-bowel obstruction. No free gas.',
                        'score'  => 5,
                    ],
                    [
                        'title'  => 'Chest radiograph — 61F, pre-operative',
                        'label'  => 'PA CHEST',
                        'brief'  => '61-year-old woman, routine pre-operative film. Asymptomatic.',
                        'answer' => 'Normal.',
                        'score'  => 5,
                    ],
                ],
            ],
            [
                'title'       => 'Paper 2 — Musculoskeletal',
                'short'       => 'P2',
                'description' => 'Thirty appendicular and axial films. Name the injury, the bone and the side; say when a film is normal.',
                'minutes'     => 35,
                'cases'       => [
                    [
                        'title'  => 'Wrist — 8M, fall on outstretched hand',
                        'label'  => 'WRIST PA/LAT',
                        'brief'  => '8-year-old boy, fell from a climbing frame onto an outstretched left hand.',
                        'answer' => 'Buckle (torus) fracture of the distal left radius, dorsal cortex. No angulation.',
                        'score'  => 5,
                    ],
                    [
                        'title'  => 'Ankle — 34F, inversion injury',
                        'label'  => 'ANKLE AP/LAT',
                        'brief'  => '34-year-old woman, inversion injury playing netball, unable to weight-bear.',
                        'answer' => 'Undisplaced transverse fracture of the right lateral malleolus at the level of the joint line (Weber B). Ankle mortise congruent.',
                        'score'  => 5,
                    ],
                    [
                        'title'  => 'Pelvis — 82F, fall at home',
                        'label'  => 'PELVIS AP',
                        'brief'  => '82-year-old woman, fell at home, pain in the left groin, shortened externally rotated leg.',
                        'answer' => 'Displaced subcapital fracture of the left femoral neck. Right hip normal. Osteopenia.',
                        'score'  => 5,
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Images
    // ------------------------------------------------------------------

    /**
     * A generated placeholder on the protected disk, through core's own upload path so it gets
     * the same policy check, naming and row an operator's upload would.
     */
    private function placeholderImage(string $paper, int $number, string $label): int
    {
        $w = 720;
        $h = 880;
        $im = imagecreatetruecolor($w, $h);

        $black  = imagecolorallocate($im, 14, 14, 16);
        $film   = imagecolorallocate($im, 44, 46, 52);
        $tissue = imagecolorallocate($im, 96, 99, 108);
        $bone   = imagecolorallocate($im, 186, 188, 196);
        $ink    = imagecolorallocate($im, 220, 222, 228);

        imagefilledrectangle($im, 0, 0, $w, $h, $black);
        imagefilledrectangle($im, 40, 40, $w - 40, $h - 40, $film);

        // A vague thorax: two darker lung fields, a brighter mediastinum, a few ribs.
        imagefilledellipse($im, 240, 430, 260, 520, $tissue);
        imagefilledellipse($im, 480, 430, 260, 520, $tissue);
        imagefilledellipse($im, 360, 470, 150, 420, $bone);

        for ($y = 200; $y < 700; $y += 62) {
            imagearc($im, 360, $y + 120, 560, 260, 190, 350, $bone);
        }

        $text = sprintf('%s  CASE %02d  %s', $paper, $number, $label);
        imagestring($im, 5, 56, 52, $text, $ink);
        imagestring($im, 3, 56, $h - 70, 'PLACEHOLDER - NOT A RADIOGRAPH', $ink);

        $tmp = tempnam(sys_get_temp_dir(), 'lumen-demo-') . '.png';
        imagepng($im, $tmp, 6);
        imagedestroy($im);

        $name = sprintf('demo-%s-case-%02d.png', strtolower($paper), $number);
        $file = new UploadedFile($tmp, $name, 'image/png', null, true);

        // The repository answers with an HTTP response rather than the row — it was written for
        // the controller that calls it — so the asset is unwrapped from it here.
        $created = app(AssetRepository::class)->create($file, ExamCase::IMAGE_USAGE, 'DESKTOP', null, false, true);
        $asset   = $created instanceof JsonResponse ? $created->getOriginalContent() : $created;

        @unlink($tmp);

        return (int) (is_array($asset) ? ($asset['id'] ?? 0) : $asset->id);
    }

    // ------------------------------------------------------------------
    // The three pages the storefront sections need
    // ------------------------------------------------------------------

    /** @return array<string,string> slug => made|kept */
    private function pages(): array
    {
        $definitions = [
            'exams' => [
                'title'   => 'Exams',
                'section' => 'EXAM_CATALOGUE_SECTION',
                'data'    => [
                    'heading'    => ['en' => 'Choose your exam'],
                    'intro'      => ['en' => 'Timed, case-based papers with model answers. Buy once; your access window starts the day you do.'],
                    'show_price' => true,
                ],
            ],
            'exam' => [
                'title'   => 'Exam',
                'section' => 'EXAM_PAPERS_SECTION',
                'data'    => [
                    'chooser_heading' => ['en' => 'Which exam?'],
                    'locked_text'     => ['en' => 'You do not have access to this exam.'],
                    'show_scores'     => true,
                ],
            ],
            'dashboard' => [
                'title'   => 'Dashboard',
                'section' => 'CANDIDATE_DASHBOARD_SECTION',
                'data'    => [
                    'heading'         => ['en' => 'Your exams'],
                    'empty_text'      => ['en' => 'You have not started any exams yet.'],
                    'signed_out_text' => ['en' => 'Sign in to see your exams.'],
                    'show_expired'    => true,
                ],
            ],
        ];

        $result = [];

        foreach ($definitions as $slug => $def) {
            $result[$slug] = BuilderPage::ensure($slug, $def['title'], $def['section'], $def['data']);
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // A few published testimonials, and the block that shows them
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function testimonials(): array
    {
        $made = 0;

        if (! Testimonial::query()->exists()) {
            $quotes = [
                ['author' => 'Dr A. Rahman',   'role' => 'ST3 radiology registrar, Leeds',     'rating' => 5,
                 'quote' => 'The closest thing to the real rapid reporting I found. The timer is unforgiving in exactly the right way.'],
                ['author' => 'Dr S. Okafor',   'role' => 'Passed 2B, spring sitting',         'rating' => 5,
                 'quote' => 'Marking myself against the model answers taught me more than any textbook — you see exactly which normals you called abnormal.'],
                ['author' => 'Dr M. Lindqvist', 'role' => 'ST4, West Midlands',               'rating' => 4,
                 'quote' => 'Two papers, thirty-five minutes each, and a score at the end. That is the whole point, and it does it well.'],
            ];

            foreach ($quotes as $i => $q) {
                Testimonial::create([
                    'author' => $q['author'],
                    'role'   => $q['role'],
                    'quote'  => ['en' => $q['quote']],
                    'rating' => $q['rating'],
                    'status' => Testimonial::STATUS_PUBLISHED,
                    'orders' => $i,
                ]);
                $made++;
            }
        }

        // Under the catalogue, where a candidate deciding whether to buy will see them.
        $block = BuilderPage::ensure('exams', 'Exams', 'TESTIMONIALS_SECTION', [
            'heading' => ['en' => 'What candidates say'],
            'limit'   => 6,
            'columns' => '3',
        ]);

        return ['made' => $made, 'block on /exams' => $block];
    }

    // ------------------------------------------------------------------
    // One candidate, enrolled, with Paper 1 already sat
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function sitting(Product $product): array
    {
        $candidate = User::query()->where('email', self::CANDIDATE_EMAIL)->first();

        if (! $candidate) {
            return ['email' => self::CANDIDATE_EMAIL, 'state' => 'no such account, nothing enrolled'];
        }

        $enrolment = Enrolment::query()
            ->where('user_id', $candidate->id)
            ->where('product_id', $product->id)
            ->newestFirst()
            ->first();

        if (! $enrolment) {
            $enrolment = app(EnrolmentRepository::class)->create([
                'user_id'    => $candidate->id,
                'product_id' => $product->id,
                'mode'       => Enrolment::MODE_PRACTICE,
                'reason'     => 'Demo content — seeded by DemoExamSeeder',
            ]);
        }

        if (PaperAttempt::query()->where('enrolment_id', $enrolment->id)->exists()) {
            return ['email' => $candidate->email, 'enrolment' => $enrolment->id, 'state' => 'kept'];
        }

        $paper = ExamPaper::query()->where('product_id', $product->id)->ordered()->first();

        if (! $paper) {
            return ['email' => $candidate->email, 'enrolment' => $enrolment->id, 'state' => 'no paper to sit'];
        }

        $cases = $paper->activeCases()->with('images')->get();

        // What the player will write once it exists: the frozen case list at open, the clock,
        // and an end time. Marked as the player would mark it, one answer per case.
        $attempt = new PaperAttempt();
        $attempt->forceFill([
            'enrolment_id'      => $enrolment->id,
            'paper_id'          => $paper->id,
            'case_ids'          => $cases->pluck('id')->all(),
            'case_snapshot'     => PaperAttempt::buildSnapshot($cases),
            'case_set_version'  => $paper->case_set_version,
            'seconds_remaining' => 0,
            'seconds_spent'     => $paper->durationSeconds(),
            'visited_case_ids'  => $cases->pluck('id')->all(),
            'started_at'        => now()->subDays(2)->subMinutes($paper->duration_minutes),
            'ended_at'          => now()->subDays(2),
        ])->save();

        $reports = [
            'Right basal consolidation and a small effusion. No pneumothorax.',
            'Line tip in the SVC. Small right apical pneumothorax.',
            'Small-bowel obstruction. No free gas seen.',
            'Normal.',
        ];
        $scores = [4.5, 5, 3.5, 5];

        foreach ($cases->values() as $i => $case) {
            $answer = new CaseAnswer();
            $answer->forceFill([
                'enrolment_id' => $enrolment->id,
                'attempt_id'   => $attempt->id,
                'case_id'      => $case->id,
                'report'       => $reports[$i] ?? 'Normal.',
                'score'        => min((float) $case->score_max, $scores[$i] ?? 4),
                'scored_at'    => now()->subDays(2)->addMinutes(10),
            ])->save();
        }

        return [
            'email'     => $candidate->email,
            'enrolment' => $enrolment->id,
            'state'     => 'made — Paper 1 sat and self-marked',
        ];
    }
}
