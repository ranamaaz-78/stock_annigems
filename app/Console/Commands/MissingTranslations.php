<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * lang:missing — every translatable text that has no translation yet.
 *
 * English text is the key (lang/{locale}.json), so a missing entry silently
 * shows English. This command lists those gaps so none slip through:
 *
 *  - __('…') / trans('…') / @lang('…') in app/ and resources/views/,
 *  - labels inside __(match …) blocks (enum labels, audit actions),
 *  - navigation labels in config/annigems.php (rendered through __()),
 *  - t('…') in resources/js, checked against lang/{locale}/js.php.
 *
 * Exits with 1 when anything is missing, so it can gate a build.
 */
class MissingTranslations extends Command
{
    protected $signature = 'lang:missing {locale=es : Language to check} {--json : Print the missing keys as JSON}';

    protected $description = 'List translatable texts with no translation in the given language';

    public function handle(): int
    {
        $locale = (string) $this->argument('locale');

        $jsonPath = lang_path($locale.'.json');
        $dictionary = File::exists($jsonPath) ? (array) json_decode(File::get($jsonPath), true) : [];
        $jsPath = lang_path($locale.'/js.php');
        $jsDictionary = File::exists($jsPath) ? (array) require $jsPath : [];

        $missing = [];

        foreach ($this->phpKeys() as $key => $where) {
            if (! $this->isTranslated($key, $dictionary)) {
                $missing['php'][$key] = $where;
            }
        }

        foreach ($this->jsKeys() as $key => $where) {
            if (! array_key_exists($key, $jsDictionary)) {
                $missing['js'][$key] = $where;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $missing === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($missing === []) {
            $this->info("No missing translations for [{$locale}].");

            return self::SUCCESS;
        }

        foreach ($missing as $kind => $keys) {
            $this->warn(strtoupper($kind).' — '.count($keys).' missing:');
            foreach ($keys as $key => $where) {
                $this->line('  '.$key.'  <fg=gray>('.$where.')</>');
            }
        }

        return self::FAILURE;
    }

    /**
     * A key counts as translated when the JSON has it, or when it names a
     * group line (validation.required, passwords.reset) that a PHP file has.
     *
     * @param  array<string, string>  $dictionary
     */
    private function isTranslated(string $key, array $dictionary): bool
    {
        if (array_key_exists($key, $dictionary)) {
            return true;
        }

        // A whole group (trans('js') for the browser strings) is not a text.
        if (File::exists(lang_path($this->argument('locale').'/'.$key.'.php'))) {
            return true;
        }

        if (preg_match('/^[a-z_]+\.[a-z0-9_.]+$/', $key) === 1) {
            return trans($key, [], (string) $this->argument('locale')) !== $key;
        }

        return false;
    }

    /**
     * Keys used from PHP and Blade, each with the first file it appears in.
     *
     * @return array<string, string>
     */
    private function phpKeys(): array
    {
        $keys = [];
        $files = array_merge(File::allFiles(app_path()), File::allFiles(resource_path('views')));

        foreach ($files as $file) {
            if ($file->getRealPath() === realpath(__FILE__)) {
                continue;
            }

            $source = $file->getContents();
            $where = $this->relative($file);

            foreach ($this->literalCalls($source, '(?:__|trans|trans_choice|@lang)') as $key) {
                $keys[$key] ??= $where;
            }

            // Labels returned through __(match …): every quoted value after =>.
            if (preg_match_all('/__\(match\s*\((?:[^()]|\([^()]*\))*\)\s*\{(.*?)\}\);/s', $source, $blocks)) {
                foreach ($blocks[1] as $block) {
                    preg_match_all("/=>\\s*'((?:[^'\\\\]|\\\\.)*)'/", $block, $labels);
                    foreach ($labels[1] as $label) {
                        $keys[stripslashes($label)] ??= $where;
                    }
                }
            }
        }

        // Settings screens translate catalog labels, help and options at render.
        foreach ([\App\Support\Settings\SettingCatalog::all(), \App\Support\Settings\BranchSettingCatalog::all()] as $definitions) {
            foreach ($definitions as $definition) {
                $keys[\Illuminate\Support\Str::headline($definition['key'])] ??= 'settings catalog';
                if (filled($definition['description'] ?? null)) {
                    $keys[$definition['description']] ??= 'settings catalog';
                }
            }
        }

        foreach (['branch_nav', 'superadmin_nav'] as $nav) {
            foreach ($this->navLabels(config('annigems.'.$nav, [])) as $label) {
                $keys[$label] ??= 'config/annigems.php '.$nav;
            }
        }

        return $keys;
    }

    /**
     * Labels and stub tooltips of a navigation tree.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return list<string>
     */
    private function navLabels(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            foreach (['label', 'stub'] as $field) {
                if (is_string($item[$field] ?? null) && $item[$field] !== '') {
                    $labels[] = $item[$field];
                }
            }
            $labels = array_merge($labels, $this->navLabels($item['children'] ?? []));
        }

        return $labels;
    }

    /**
     * Keys used from browser scripts: t('…') calls.
     *
     * @return array<string, string>
     */
    private function jsKeys(): array
    {
        $keys = [];
        $files = array_merge(File::allFiles(resource_path('js')), File::allFiles(resource_path('views')));

        foreach ($files as $file) {
            foreach ($this->literalCalls($file->getContents(), '(?<![\w.$])t') as $key) {
                $keys[$key] ??= $this->relative($file);
            }
        }

        return $keys;
    }

    /**
     * First-argument string literals of calls to the given function name.
     *
     * @return list<string>
     */
    private function literalCalls(string $source, string $function): array
    {
        $found = [];
        $pattern = '/'.$function.'\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`((?:[^`\\\\$]|\\\\.)*)`)\s*[,)]/s';

        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $raw = ($match[1] ?? '') !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
                if ($raw !== '') {
                    $found[] = stripcslashes($raw);
                }
            }
        }

        return $found;
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace('\\', '/', $file->getRelativePathname());
    }
}
