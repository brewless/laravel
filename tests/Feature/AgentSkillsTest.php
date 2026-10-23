<?php

declare(strict_types=1);

use Brewless\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

uses(TestCase::class);

const SKILLS_PATH = __DIR__.'/../../resources/boost/skills';

const GUIDELINES_PATH = __DIR__.'/../../resources/boost/guidelines';

/**
 * The commands of the brewless client (brewless/cli), so a skill never tells an
 * agent to run one that does not exist.
 */
const CLI_COMMANDS = [
    'login', 'logout', 'init', 'deploy', 'plan', 'service:push', 'releases', 'rollback', 'env:pull', 'env:push',
    'provision', 'domain:add', 'dns:list', 'dns:add', 'dns:update', 'dns:remove', 'scale', 'command', 'logs', 'export', 'detach',
];

/**
 * @return array<string, array{0: string}>
 */
function skillDirectories(): array
{
    $directories = glob(SKILLS_PATH.'/*', GLOB_ONLYDIR) ?: [];

    return array_combine(array_map(basename(...), $directories), array_map(static fn (string $directory): array => [$directory], $directories));
}

/**
 * The frontmatter as Laravel Boost reads it: YAML between the first two lines of dashes.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function skillParts(string $directory): array
{
    $content = (string) file_get_contents($directory.'/SKILL.md');

    expect(preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $content, $matches))->toBe(1, basename($directory).' has no frontmatter');

    return [Yaml::parse($matches[1]), $matches[2]];
}

test('the package ships skills and a guideline where Laravel Boost looks for them', function (): void {
    expect(skillDirectories())->toHaveKeys(['brewless-deployment', 'brewless-configuration', 'brewless-operations', 'brewless-databases'])
        ->and(GUIDELINES_PATH.'/core.md')->toBeFile();
});

test('every skill follows the Agent Skills format', function (string $directory): void {
    [$frontmatter, $body] = skillParts($directory);

    // Boost and the Agent Skills specification both require a name and a description.
    expect($frontmatter['name'] ?? null)->toBe(basename($directory))
        ->and($frontmatter['name'])->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/')
        ->and(strlen($frontmatter['name']))->toBeLessThanOrEqual(64)
        ->and($frontmatter['description'] ?? null)->toBeString()->not->toBeEmpty()
        ->and(strlen($frontmatter['description']))->toBeLessThanOrEqual(1024)
        ->and(substr_count($body, "\n"))->toBeLessThan(500);
})->with(skillDirectories());

test('a skill or the guideline names only commands the brewless client has', function (string $file): void {
    preg_match_all('/\bbrewless ([a-z]+(?::[a-z]+)?)\b/', (string) file_get_contents($file), $matches);

    $unknown = array_values(array_diff(array_unique($matches[1]), CLI_COMMANDS, ['laravel', 'cli']));

    expect($unknown)->toBe([], basename(dirname($file)).' names commands the client does not have');
})->with(fn (): array => [...array_map(static fn (array $directory): string => $directory[0].'/SKILL.md', skillDirectories()), GUIDELINES_PATH.'/core.md']);

test('the guideline is plain Markdown, short enough to load in every session', function (): void {
    $guideline = (string) file_get_contents(GUIDELINES_PATH.'/core.md');

    expect(str_word_count($guideline))->toBeLessThan(600)
        ->and($guideline)->toContain('## Brewless');
});

test('without Laravel Boost, the skills can be published where agents read them', function (string $tag, string $target): void {
    File::deleteDirectory(base_path($target));

    $this->artisan('vendor:publish', ['--tag' => $tag])->assertSuccessful();

    foreach (array_keys(skillDirectories()) as $skill) {
        expect(base_path($target.'/'.$skill.'/SKILL.md'))->toBeFile();
    }

    File::deleteDirectory(base_path(dirname($target)));
})->with([
    'Codex, Amp, OpenCode, Zed and others' => ['brewless-skills', '.agents/skills'],
    'Claude Code' => ['brewless-skills-claude', '.claude/skills'],
]);
