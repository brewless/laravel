<?php

declare(strict_types=1);

namespace Brewless\Laravel\WebMcp;

use Closure;
use InvalidArgumentException;

use function file_get_contents;
use function json_encode;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function str_starts_with;

/**
 * The WebMCP tools of the application: what an agent in the visitor's browser
 * may call instead of reading or clicking through the page (WebMCP, a draft of
 * the W3C Web Machine Learning Community Group). `@webmcp` in the layout puts
 * them on every page.
 *
 * Only tools that read: `data()` hands over what the page already knows, and
 * `fetch()` asks a GET route of the application itself. Nothing here can send,
 * buy or delete: that stays with a form the visitor sends, which can carry
 * WebMCP attributes of its own (`toolname`, `tooldescription`).
 *
 * Register them once, in a service provider. What they hand over is the same
 * for every visitor, so the pages they are on can stay at the edge.
 */
final class WebMcp
{
    /** A tool's name: what an agent calls it by. */
    private const string NAME = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * Words that tell an agent to act without the person behind it. A tool
     * description is read by the agent, never by the visitor, so such an
     * instruction is one nobody agreed to.
     */
    private const string SKIPS_THE_PERSON = '/\b(without|skip(?:ping)?|no need to|need not|do not|don\'t|never)\s+(?:\w+\s+){0,2}(ask|asking|confirm|confirming|confirmation|check(?:ing)? with)\b/i';

    /** @var array<string, array{kind: string, name: string, title: string|null, description: string, data?: mixed, url?: string, inputSchema: array<string, mixed>, untrusted: bool}> */
    private array $tools = [];

    private static ?string $runtime = null;

    /**
     * A tool that hands over data the application has: contact details, opening
     * hours, prices. A closure is asked when a page is rendered.
     *
     * @param  array<array-key, mixed>|Closure(): array<array-key, mixed>  $data
     */
    public function data(string $name, string $description, array|Closure $data, ?string $title = null): self
    {
        $this->tools[$name] = [
            'kind' => 'data',
            'name' => self::name($name),
            'title' => $title,
            'description' => self::description($description),
            'data' => $data,
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'untrusted' => false,
        ];

        return $this;
    }

    /**
     * A tool that asks a GET route of the application, with what the agent
     * gives as the query string, and hands over the answer. Only an address of
     * the application itself, given as a path (`/api/search` or `route(...,
     * absolute: false)`). `$untrusted`: the answer holds what visitors or
     * others wrote, which an agent must not take as an instruction.
     *
     * @param  array<string, mixed>  $inputSchema  JSON Schema of an object; its properties become the query string
     */
    public function fetch(string $name, string $description, string $path, array $inputSchema = ['type' => 'object', 'properties' => []], ?string $title = null, bool $untrusted = false): self
    {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || parse_url($path, PHP_URL_HOST) !== null) {
            throw new InvalidArgumentException("WebMCP tool [{$name}]: give a path of this application, such as /api/search, not [{$path}].");
        }

        if (($inputSchema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException("WebMCP tool [{$name}]: the input schema describes an object.");
        }

        $this->tools[$name] = [
            'kind' => 'fetch',
            'name' => self::name($name),
            'title' => $title,
            'description' => self::description($description),
            'url' => $path,
            'inputSchema' => $inputSchema,
            'untrusted' => $untrusted,
        ];

        return $this;
    }

    /**
     * The tools as a page hands them to the browser.
     *
     * @return list<array<string, mixed>>
     */
    public function tools(): array
    {
        $tools = [];

        foreach ($this->tools as $tool) {
            if ($tool['kind'] === 'data' && $tool['data'] instanceof Closure) {
                $tool['data'] = ($tool['data'])();
            }

            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * What `@webmcp` puts in a page: the tools, and the script that registers
     * them and offers `window.BrewlessWebMcp` for tools of the page's own.
     */
    public function render(): string
    {
        $json = json_encode($this->tools(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        return '<script type="application/json" id="brewless-webmcp">'.$json.'</script>'
            .'<script>'.self::runtime().'</script>';
    }

    private static function runtime(): string
    {
        return self::$runtime ??= (string) file_get_contents(__DIR__.'/../../resources/js/webmcp.js');
    }

    private static function name(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException("WebMCP tool [{$name}]: a name is lowercase letters, digits and underscores, starting with a letter, at most 64.");
        }

        return $name;
    }

    private static function description(string $description): string
    {
        if ($description === '' || mb_strlen($description) > 1000) {
            throw new InvalidArgumentException('A WebMCP tool needs a description of at most 1000 characters: it is what an agent decides by.');
        }

        if (preg_match(self::SKIPS_THE_PERSON, $description) === 1) {
            throw new InvalidArgumentException('A WebMCP tool description may not tell an agent to act without asking the person it works for.');
        }

        return $description;
    }
}
