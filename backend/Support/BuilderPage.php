<?php

namespace Theme\Backend\Support;

use App\Models\Page;
use App\Repositories\Meta\MetaRepository;

/**
 * A page carrying one section, made the way the page builder would make it.
 *
 * Two seeders need this — the demo exam's three pages and the starter forms' pages — and the
 * node shape is the kind of thing that drifts when written twice: a ROW carrying a full-width
 * COLUMN carrying the SECTION, each a `metas` row whose parent is the row above it, with the
 * section's content under `data` and its Status in the column core's own renderer reads.
 */
class BuilderPage
{
    /**
     * Find the page by slug or create it, and make sure it carries the section.
     *
     * A page that already has the section — anywhere, in any row — is left exactly as the
     * operator has it. One that does not gets a new full-width row appended below whatever is
     * there, so a page inherited from another theme keeps its blocks and gains this one.
     *
     * @param  array<string,mixed>  $sectionData  the section's content, in the shape its schema saves
     * @return string  `made` when the page or the row was created, `kept` when the section was already there
     */
    public static function ensure(string $slug, string $title, string $sectionType, array $sectionData): string
    {
        $page = Page::query()->where('slug->en', $slug)->first();

        if (! $page) {
            $page = Page::create([
                'title'     => ['en' => $title],
                'slug'      => ['en' => $slug],
                'type'      => 'PAGE',
                'status'    => 'active',
                'fixed'     => false,
                'deletable' => true,
            ]);
        }

        if (self::carries($page, $sectionType)) {
            return 'kept';
        }

        $order = (int) $page->rows()->max('orders') + 1;

        app(MetaRepository::class)->updateOrCreateMetaRecursive($page, [
            'type'     => 'ROW',
            'status'   => 'active',
            'data'     => ['layout' => 'full', 'status' => 'active'],
            'children' => [[
                'type'     => 'COLUMN',
                'status'   => 'active',
                'data'     => ['width' => 12, 'status' => 'active'],
                'children' => [[
                    'type'   => $sectionType,
                    'status' => 'active',
                    'data'   => $sectionData + ['status' => 'active'],
                ]],
            ]],
        ], $order);

        return 'made';
    }

    /** Whether any section of this type sits anywhere on the page. */
    private static function carries(Page $page, string $sectionType): bool
    {
        foreach ($page->rows()->with('children.children')->get() as $row) {
            foreach ($row->children as $column) {
                if ($column->children->contains(fn ($section) => $section->type === $sectionType)) {
                    return true;
                }
            }
        }

        return false;
    }
}
