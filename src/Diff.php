<?php

namespace Overtrue\LaravelVersionable;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\DiffHelper;

/**
 * @see https://github.com/jfcherng/php-diff#example
 */
class Diff
{
    public function __construct(
        public Version $newVersion,
        public Version $oldVersion,
        public array $differOptions = [],
        public array $renderOptions = []
    ) {
        // keep the old version always smaller than the new version
        if ($this->oldVersion->created_at > $this->newVersion->created_at
            || $this->oldVersion->id > $this->newVersion->id && $this->oldVersion->created_at > $this->newVersion->created_at
        ) {
            [$this->oldVersion, $this->newVersion] = [$this->newVersion, $this->oldVersion];
        }
    }

    public function toArray(array $differOptions = [], array $renderOptions = [], bool $stripTags = false): array
    {
        return $this->render(null, $differOptions, $renderOptions, $stripTags);
    }

    public function toText(array $differOptions = [], array $renderOptions = [], bool $stripTags = false): array
    {
        return $this->render('Unified', $differOptions, $renderOptions, $stripTags);
    }

    public function toJsonText(array $differOptions = [], array $renderOptions = [], bool $stripTags = false): array
    {
        return $this->render('JsonText', $differOptions, $renderOptions, $stripTags);
    }

    public function toContextText(array $differOptions = [], array $renderOptions = [], bool $stripTags = false): array
    {
        return $this->render('Context', $differOptions, $renderOptions, $stripTags);
    }

    public function toHtml(array $differOptions = [], array $renderOptions = [], bool $stripTags = false): array
    {
        return $this->render('Combined', $differOptions, $renderOptions, $stripTags);
    }

    public function toInlineHtml(array $differOptions = [], array $renderOptions = [], bool $stripTags = false): array
    {
        return $this->render('Inline', $differOptions, $renderOptions, $stripTags);
    }

    public function toJsonHtml(array $differOptions = [], array $renderOptions = [], bool $stripTags = false): array
    {
        return $this->render('JsonHtml', $differOptions, $renderOptions, $stripTags);
    }

    public function toSideBySideHtml(array $differOptions = [], array $renderOptions = [], bool $stripTags = false): array
    {
        return $this->render('SideBySide', $differOptions, $renderOptions, $stripTags);
    }

    protected function getContents(bool $stripTags = false): array
    {
        $newContents = $this->newVersion->contents;

        // if the version strategy is DIFF, we need to merge the contents of all versions
        // from v1 to v2, v2 to v3, ..., vn-1 to vn.
        if ($this->newVersion->versionable?->getVersionStrategy() === VersionStrategy::DIFF) {
            $oldContents = [];
            // all versions before this version.
            $versionsBeforeThis = $this->newVersion->previousVersions()->get();

            foreach ($versionsBeforeThis as $version) {
                if (! empty($version->contents)) {
                    $oldContents = array_merge($oldContents, $version->contents);
                }
            }

            $oldContents = Arr::only($oldContents, array_keys($newContents));
        } else {
            $oldContents = $this->oldVersion->contents;
        }

        if ($stripTags) {
            $oldContents = array_map(fn ($item) => strip_tags($item), $oldContents);
            $newContents = array_map(fn ($item) => strip_tags($item), $newContents);
        }

        return [$oldContents, $newContents];
    }

    public function render(?string $renderer = null, array $differOptions = [], array $renderOptions = [], bool $stripTags = false): array
    {
        if (empty($differOptions)) {
            $differOptions = $this->differOptions;
        }

        if (empty($renderOptions)) {
            $renderOptions = $this->renderOptions;
        }

        [$oldContents, $newContents] = $this->getContents($stripTags);

        $diff = [];

        $createDiff = function ($key, $old, $new) use (&$diff, $renderer, $differOptions, $renderOptions) {
            if ($renderer) {
                $old = is_string($old) ? $old : json_encode($old, JSON_PRETTY_PRINT);
                $new = is_string($new) ? $new : json_encode($new, JSON_PRETTY_PRINT);
                $diff[$key] = str_replace('\n No newline at end of file', '', DiffHelper::calculate($old, $new, $renderer, $differOptions, $renderOptions));
            } else {
                $diff[$key] = compact('old', 'new');
            }
        };

        foreach ($oldContents as $key => $value) {
            $createDiff($key, Arr::get($oldContents, $key), Arr::get($newContents, $key));
        }

        foreach (array_diff_key($newContents, $oldContents) as $key => $value) {
            $createDiff($key, null, $value);
        }

        return $diff;
    }

    public function getStatistics(array $differOptions = [], bool $stripTags = false): array
    {
        if (empty($differOptions)) {
            $differOptions = $this->differOptions;
        }

        [$oldContents, $newContents] = $this->getContents($stripTags);

        $diffStats = new Collection;

        foreach ($newContents as $key => $newContent) {
            $oldContent = $oldContents[$key] ?? null;

            if (! isset($oldContents[$key])) {
                $diffStats->push([
                    'inserted' => is_string($newContent)
                        ? substr_count($newContent, "\n") + 1
                        : 1,
                    'deleted' => 0,
                    'unmodified' => 0,
                    'changedRatio' => 1,
                ]);
            } elseif ($newContent !== $oldContent) {
                $diffStats->push(
                    (new Differ(
                        explode("\n", is_string($oldContent) ? $oldContent : json_encode($oldContent)),
                        explode("\n", is_string($newContent) ? $newContent : json_encode($newContent)),
                        $differOptions,
                    ))->getStatistics()
                );
            }
        }

        return [
            'inserted' => $diffStats->sum('inserted'),
            'deleted' => $diffStats->sum('deleted'),
            'unmodified' => $diffStats->sum('unmodified'),
            'changedRatio' => $diffStats->sum('changedRatio'),
        ];
    }
}
