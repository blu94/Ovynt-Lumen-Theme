<?php

namespace Theme\Backend\Seeders;

use App\Models\Form;
use App\Repositories\Form\FormRepository;
use Illuminate\Support\Facades\DB;
use Theme\Backend\Support\BuilderPage;

/**
 * The three forms an exam site needs, and a page for each.
 *
 * Contact, site feedback and a case report. All three are ordinary forms under **Forms** —
 * core's module, core's lead storage, core's notify-on-submit — so the operator can change a
 * field or the wording without touching the theme. What the theme adds is the page each sits on
 * (an Inquiry Form block on `contact`, `feedback` and `report-a-case`) and, on the case-report
 * form, the three fields a link from inside a sitting fills in and locks: exam, paper, case.
 *
 * A testimonial is deliberately not a fourth form. A candidate who wants to say something kind
 * uses the feedback form; the message lands in Leads as correspondence, and what gets published
 * is a row in the Testimonials module — content somebody reviewed. See that module's migration.
 *
 * Run by hand, like the demo seeder, and safe to re-run: a form that already exists by slug is
 * left exactly as the operator has it, and a page that already has rows is kept.
 */
class StarterFormsSeeder
{
    /** @return array<string,array<string,string>> */
    public function run(): array
    {
        return DB::transaction(function () {
            $forms = [];

            foreach ($this->definitions() as $slug => $form) {
                $forms[$slug] = $this->form($slug, $form);
            }

            $pages = [
                'contact'       => BuilderPage::ensure('contact', 'Contact', 'INQUIRY_FORM_SECTION', [
                    'form_slug' => 'contact',
                    'heading'   => ['en' => 'Get in touch'],
                    'intro'     => ['en' => 'A question about an exam, access, or an order — we reply to every message.'],
                ]),
                'feedback'      => BuilderPage::ensure('feedback', 'Feedback', 'INQUIRY_FORM_SECTION', [
                    'form_slug' => 'site-feedback',
                    'heading'   => ['en' => 'Tell us how it went'],
                    'intro'     => ['en' => 'What worked, what got in the way, and anything you would tell a colleague sitting the same exam.'],
                ]),
                'report-a-case' => BuilderPage::ensure('report-a-case', 'Report a case', 'INQUIRY_FORM_SECTION', [
                    'form_slug' => 'case-report',
                    'heading'   => ['en' => 'Report a problem with a case'],
                    'intro'     => ['en' => 'An image that will not load, a model answer you think is wrong, a brief that does not match the film. Say which case and we will look at it.'],
                ]),
            ];

            return ['forms' => $forms, 'pages' => $pages];
        });
    }

    private function form(string $slug, array $definition): string
    {
        if (Form::query()->where('slug->en', $slug)->exists()) {
            return 'kept';
        }

        app(FormRepository::class)->create([
            'title'       => ['en' => $definition['title']],
            'subtitle'    => ['en' => $definition['subtitle'] ?? ''],
            'description' => ['en' => $definition['description'] ?? ''],
            'slug'        => ['en' => $slug],
            'status'      => 'active',
            'fields'      => array_map(fn (array $f) => [
                'type'        => $f['type'],
                'title'       => ['en' => $f['label']],
                'description' => ['en' => $f['hint'] ?? ''],
                'status'      => 'active',
                'data'        => array_filter([
                    'required' => $f['required'] ?? false,
                    'options'  => $f['options'] ?? null,
                ]),
            ], $definition['fields']),
        ]);

        return 'made';
    }

    /**
     * @return array<string,array{title:string,subtitle?:string,description?:string,fields:array<int,array<string,mixed>>}>
     */
    private function definitions(): array
    {
        $identity = [
            ['type' => 'text',  'label' => 'Name',  'required' => true],
            ['type' => 'email', 'label' => 'Email', 'required' => true, 'hint' => 'Where the reply goes.'],
        ];

        return [
            'contact' => [
                'title'       => 'Contact',
                'description' => 'A question about an exam, your access or an order.',
                'fields'      => [
                    ...$identity,
                    ['type' => 'textarea', 'label' => 'Message', 'required' => true],
                    ['type' => 'submit',   'label' => 'Send'],
                ],
            ],
            'site-feedback' => [
                'title'       => 'Site feedback',
                'description' => 'How the site and the exams are working for you.',
                'fields'      => [
                    ...$identity,
                    [
                        'type'    => 'select',
                        'label'   => 'What were you doing?',
                        'options' => 'Sitting a paper, Reviewing my answers, Buying an exam, Something else',
                    ],
                    ['type' => 'textarea', 'label' => 'Feedback', 'required' => true],
                    [
                        'type'    => 'checkbox',
                        'label'   => 'Permission',
                        'options' => 'You may quote me on the site',
                        'hint'    => 'Tick this and we may publish part of what you wrote, with your name, as a testimonial. We will ask before using your full name.',
                    ],
                    ['type' => 'submit', 'label' => 'Send feedback'],
                ],
            ],
            'case-report' => [
                'title'       => 'Case report',
                'description' => 'A problem with one case: an image, a brief, a model answer.',
                'fields'      => [
                    ...$identity,
                    // Filled in and locked by the link that opens this form from a sitting.
                    ['type' => 'text', 'label' => 'Exam',  'required' => true],
                    ['type' => 'text', 'label' => 'Paper', 'required' => true],
                    ['type' => 'text', 'label' => 'Case',  'required' => true, 'hint' => 'The case number as shown in the paper.'],
                    [
                        'type'     => 'select',
                        'label'    => 'What is wrong?',
                        'required' => true,
                        'options'  => 'The image will not load, The model answer is wrong, The brief does not match the image, Something else',
                    ],
                    ['type' => 'textarea', 'label' => 'Details', 'required' => true],
                    ['type' => 'submit',   'label' => 'Send report'],
                ],
            ],
        ];
    }
}
