<?php
// careerhack.ru/pull.php
// Pulls a subdirectory from a GitHub repo and overwrites the directory this file lives in.
// Configuration lives in ./pull-config.php (next to this file). Delete pull-config.php to re-run setup.
//
// Usage: open https://<your-host>/pull.php in a browser.
// Append ?plain=1 for unstyled text/plain output (useful for cron / curl).

declare(strict_types=1);

const CONFIG_FILE = 'pull-config.php';
const ALWAYS_KEEP = ['pull.php', 'pull-config.php'];

$configPath = __DIR__ . '/' . CONFIG_FILE;
$plain      = isset($_GET['plain']);

// First-run setup: render form (GET) or write config (POST)
if (!file_exists($configPath)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_setup_post($configPath);
    } else {
        render_setup_form();
    }
    exit;
}

$config = load_config($configPath);
date_default_timezone_set($config['timezone'] ?: 'UTC');

ignore_user_abort(true);
@set_time_limit(300);

// Optional IP allowlist (KRASKI_PULL_ALLOW_IPS, comma-separated) restricts who
// may trigger a pull. Empty = no restriction.
$denied = null;
$allow = array_filter(array_map(
    'trim',
    explode(',', (string)(getenv('KRASKI_PULL_ALLOW_IPS') ?: ''))
));
if ($allow !== [] && !in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), $allow, true)) {
    $denied = 'forbidden: IP not allowed';
}
if ($denied !== null) {
    http_response_code(403);
    if ($plain) {
        header('Content-Type: text/plain; charset=utf-8');
        exit($denied . "\n");
    }
    header('Content-Type: text/html; charset=utf-8');
    exit("<!doctype html><meta charset=\"utf-8\"><title>403</title><body style=\"background:#1e1e1e;color:#ff6b6b;font-family:monospace;padding:2rem\">"
        . htmlspecialchars($denied, ENT_QUOTES) . "</body>");
}

start_output($plain);

$tz       = date_default_timezone_get();
$startTs  = microtime(true);
$startStr = date('Y-m-d H:i:s');

term("================================================");
term("  START:    {$startStr} ({$tz})");
term("================================================");
term("");

$target = __DIR__;
$tmp    = sys_get_temp_dir() . '/pull_' . bin2hex(random_bytes(6));
if (!mkdir($tmp, 0755, true)) {
    http_response_code(500);
    term("error: cannot create temp dir");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

$ghToken = (string)(getenv('GITHUB_TOKEN') ?: $config['gh_token']);
$authHeaders = $ghToken !== '' ? ["Authorization: Bearer {$ghToken}"] : [];

$zipUrls = [
    sprintf('https://api.github.com/repos/%s/zipball/%s', $config['repo'], $config['branch']),
    sprintf('https://codeload.github.com/%s/zip/refs/heads/%s', $config['repo'], $config['branch']),
    sprintf('https://github.com/%s/archive/refs/heads/%s.zip', $config['repo'], $config['branch']),
];
$zipFile = $tmp . '/repo.zip';

if ($ghToken === '') {
    term("warning: no GitHub token set — private repo downloads will return 404");
}

$bytes = 0;
$lastErr = '';
foreach ($zipUrls as $zipUrl) {
    term("downloading {$zipUrl}");
    [$bytes, $lastErr] = download($zipUrl, $zipFile, $authHeaders);
    if ($bytes > 0) break;
    term("  failed: {$lastErr}");
}
if ($bytes <= 0) {
    cleanup($tmp);
    http_response_code(502);
    term("download failed: {$lastErr}");
    if ($ghToken !== '' && strpos($lastErr, 'http 404') !== false) {
        term("hint: 404 with a token = token lacks access to this private repo.");
        term("  - classic PAT: needs full 'repo' scope (not just 'public_repo')");
        term("  - fine-grained PAT: must include this repo under 'Repository access'");
        term("    AND grant 'Repository permissions -> Contents: Read'");
        term("  also check the token isn't expired or revoked");
    }
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}
term("downloaded {$bytes} bytes");

if (!class_exists('ZipArchive')) {
    cleanup($tmp);
    http_response_code(500);
    term("error: ZipArchive extension not available");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    cleanup($tmp);
    http_response_code(500);
    term("error: cannot open zip");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}
if (!$zip->extractTo($tmp)) {
    $zip->close();
    cleanup($tmp);
    http_response_code(500);
    term("error: extract failed");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}
$zip->close();
term("extracted archive");

$src = null;
$subdir = $config['subdir'] !== '' ? $config['subdir'] : '.';
foreach (scandir($tmp) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $candidate = $subdir === '.' ? $tmp . '/' . $entry : $tmp . '/' . $entry . '/' . $subdir;
    if (is_dir($candidate)) { $src = $candidate; break; }
}
if ($src === null) {
    cleanup($tmp);
    http_response_code(500);
    term("error: '{$subdir}' not found inside the archive");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

$keep = array_values(array_unique(array_merge(ALWAYS_KEEP, $config['keep_files'])));
term("copying into {$target} (preserving: " . implode(', ', $keep) . ")");
$copied = 0;
copyTree($src, $target, $keep, $copied);
cleanup($tmp);

term("copied {$copied} files");
print_finish_banner($startTs, $startStr, $tz, true, $copied);
end_output();
exit;

// ---------- output helpers ----------

function start_output(bool $plain): void {
    global $TERM_PLAIN;
    $TERM_PLAIN = $plain;

    @header('Cache-Control: no-cache, no-store, must-revalidate');
    @header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) @ob_end_flush();
    @ob_implicit_flush(true);

    if ($plain) {
        @header('Content-Type: text/plain; charset=utf-8');
        return;
    }

    @header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<title>pull.php</title>";
    echo terminal_css();
    echo "</head><body><div class=\"term\"><div class=\"bar\">";
    echo "<span class=\"dot r\"></span><span class=\"dot y\"></span><span class=\"dot g\"></span>";
    echo "<span class=\"title\">pull.php — " . htmlspecialchars(gethostname() ?: 'localhost', ENT_QUOTES, 'UTF-8') . "</span>";
    echo "<a class=\"home\" href=\"/\" title=\"Go to site root\">⌂ site root</a></div>";
    echo "<pre id=\"out\" class=\"out\"></pre></div>";
    echo terminal_js();
    echo str_repeat(' ', 1024); // flush kicker for some buffering proxies
    flush();
}

function end_output(): void {
    global $TERM_PLAIN;
    if (!$TERM_PLAIN) {
        echo "<script>window.__pullDone=true;</script></body></html>";
    }
    flush();
}

function term(string $msg): void {
    global $TERM_PLAIN;
    if ($TERM_PLAIN) {
        echo $msg . "\n";
        flush();
        return;
    }
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo "<i class=\"line\" data-t=\"" . $safe . "\"></i>";
    echo str_repeat(' ', 256);
    flush();
}

function print_finish_banner(float $startTs, string $startStr, string $tz, bool $ok, int $copied = 0): void {
    $endTs   = microtime(true);
    $endStr  = date('Y-m-d H:i:s');
    $dur     = $endTs - $startTs;
    $status  = $ok ? 'DONE' : 'FAILED';
    term("");
    term("================================================");
    term("  STATUS:   {$status}");
    term("  START:    {$startStr} ({$tz})");
    term("  END:      {$endStr} ({$tz})");
    term("  DURATION: " . number_format($dur, 2) . " seconds");
    if ($ok) {
        term("  FILES:    {$copied}");
    }
    term("================================================");
}

// ---------- config loader ----------

function load_config(string $path): array {
    $raw = require $path;
    if (!is_array($raw)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("pull-config.php must return an array\n");
    }
    return [
        'repo'       => (string)($raw['repo']       ?? ''),
        'branch'     => (string)($raw['branch']     ?? 'main'),
        'subdir'     => (string)($raw['subdir']     ?? '.'),
        'gh_token'   => (string)($raw['gh_token']   ?? ''),
        'keep_files' => is_array($raw['keep_files'] ?? null) ? array_values($raw['keep_files']) : [],
        'timezone'   => (string)($raw['timezone']   ?? 'UTC'),
    ];
}

// ---------- download / copy ----------

function download(string $url, string $dest, array $extraHeaders = []): array {
    @unlink($dest);
    if (function_exists('curl_init')) {
        $fp = fopen($dest, 'wb');
        if (!$fp) return [0, 'cannot open destination file'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'pull.php',
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER     => $extraHeaders,
        ]);
        $ok       = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errstr   = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $errno !== 0) {
            return [0, "curl errno {$errno}: {$errstr} (http {$httpCode})"];
        }
        if ($httpCode >= 400) {
            return [0, "http {$httpCode}"];
        }
        $size = (int)@filesize($dest);
        return [$size, $size > 0 ? '' : 'empty file'];
    }
    if (!ini_get('allow_url_fopen')) {
        return [0, 'no curl and allow_url_fopen=Off'];
    }
    $hdr = "User-Agent: pull.php\r\n";
    foreach ($extraHeaders as $h) $hdr .= $h . "\r\n";
    $ctx = stream_context_create(['http' => [
        'header'          => $hdr,
        'timeout'         => 300,
        'follow_location' => 1,
    ]]);
    $err = '';
    set_error_handler(function ($_, $msg) use (&$err) { $err = $msg; return true; });
    $ok = copy($url, $dest, $ctx);
    restore_error_handler();
    if (!$ok) return [0, $err ?: 'copy() failed'];
    $size = (int)@filesize($dest);
    return [$size, $size > 0 ? '' : 'empty file'];
}

function copyTree(string $from, string $to, array $keepTopLevel, int &$copied): void {
    if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) return;
    foreach (new DirectoryIterator($from) as $f) {
        if ($f->isDot()) continue;
        $name = $f->getFilename();
        if ($keepTopLevel && in_array($name, $keepTopLevel, true)) continue;
        $s = $f->getPathname();
        $d = $to . '/' . $name;
        if ($f->isDir()) {
            copyTree($s, $d, [], $copied);
        } else {
            @copy($s, $d);
            $copied++;
        }
    }
}

function cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

// ---------- first-run setup ----------

function handle_setup_post(string $configPath): void {
    $repo     = trim((string)($_POST['repo']     ?? ''));
    $branch   = trim((string)($_POST['branch']   ?? 'main'));
    $subdir   = trim((string)($_POST['subdir']   ?? '.'));
    $ghToken  = (string)($_POST['gh_token'] ?? '');
    $timezone = trim((string)($_POST['timezone'] ?? 'UTC'));
    $keepRaw  = (string)($_POST['keep_files'] ?? '');

    $errors = [];
    if ($repo === '' || !preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~', $repo)) {
        $errors[] = 'repo must look like "owner/name"';
    }
    if ($branch === '') $branch = 'main';
    if ($subdir === '') $subdir = '.';
    if ($timezone === '' || @timezone_open($timezone) === false) {
        $errors[] = 'invalid timezone (e.g. "Europe/Moscow", "UTC")';
    }

    $keepFiles = [];
    foreach (preg_split('/[\s,]+/', $keepRaw) ?: [] as $name) {
        $name = trim($name);
        if ($name !== '') $keepFiles[] = $name;
    }
    foreach (ALWAYS_KEEP as $must) {
        if (!in_array($must, $keepFiles, true)) $keepFiles[] = $must;
    }

    if ($errors) {
        render_setup_form([
            'repo' => $repo, 'branch' => $branch, 'subdir' => $subdir,
            'gh_token' => $ghToken,
            'timezone' => $timezone, 'keep_files' => implode(', ', $keepFiles),
        ], $errors);
        return;
    }

    $config = [
        'repo'       => $repo,
        'branch'     => $branch,
        'subdir'     => $subdir,
        'gh_token'   => $ghToken,
        'keep_files' => $keepFiles,
        'timezone'   => $timezone,
    ];

    $body = "<?php\n"
          . "// pull.php config — generated " . gmdate('c') . "\n"
          . "// Edit values below or delete this file to re-run the setup form.\n\n"
          . "return " . var_export($config, true) . ";\n";

    if (@file_put_contents($configPath, $body, LOCK_EX) === false) {
        render_setup_form([
            'repo' => $repo, 'branch' => $branch, 'subdir' => $subdir,
            'gh_token' => $ghToken,
            'timezone' => $timezone, 'keep_files' => implode(', ', $keepFiles),
        ], ['cannot write pull-config.php — check directory permissions']);
        return;
    }

    @chmod($configPath, 0600);
    render_setup_done($config);
}

function render_setup_form(array $values = [], array $errors = []): void {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $repo     = $h($values['repo']     ?? '');
    $branch   = $h($values['branch']   ?? 'main');
    $subdir   = $h($values['subdir']   ?? '.');
    $ghToken  = $h($values['gh_token'] ?? '');
    $timezone = $h($values['timezone'] ?? 'UTC');
    $keep     = $h($values['keep_files'] ?? 'pull.php, pull-config.php');

    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<title>pull.php — first-run setup</title>";
    echo terminal_css();
    echo "</head><body>";
    echo "<div class=\"term\"><div class=\"bar\">";
    echo "<span class=\"dot r\"></span><span class=\"dot y\"></span><span class=\"dot g\"></span>";
    echo "<span class=\"title\">pull.php — first-run setup</span>";
    echo "<a class=\"home\" href=\"/\" title=\"Go to site root\">⌂ site root</a></div>";
    echo "<div id=\"intro\" class=\"out\"></div>";

    // The intro text typed out by JS. data-intro carries the script.
    $intro = [
        ['p',   '$ pull.php — first-run setup'],
        ['',    ''],
        ['c',   '# No pull-config.php found in this directory.'],
        ['c',   '# Fill the form below to generate it. Each field is explained.'],
        ['c',   '# Tip: click anywhere to skip the typing animation.'],
        ['',    ''],
    ];
    if ($errors) {
        $intro[] = ['e', '! Please fix the following:'];
        foreach ($errors as $e) $intro[] = ['e', '!   - ' . $e];
        $intro[] = ['', ''];
    }
    $introJson = htmlspecialchars(json_encode($intro), ENT_QUOTES, 'UTF-8');

    echo "<form id=\"setup\" method=\"post\" class=\"form hidden\">";
    echo "<input type=\"hidden\" name=\"_setup\" value=\"1\">";

    setup_field(
        'repo', 'GitHub repository',
        $repo, 'owner/name', false,
        [
            'Format: <code>owner/name</code> (e.g. <code>torvalds/linux</code>).',
            'Where to get it: open the repo on github.com — the URL is <code>github.com/&lt;owner&gt;/&lt;name&gt;</code>. Copy the <code>owner/name</code> part.',
        ]
    );

    setup_field(
        'branch', 'Branch',
        $branch, 'main', false,
        [
            'Branch to pull from. Usually <code>main</code> (modern repos) or <code>master</code> (older repos).',
            'Where to get it: on the repo page on github.com, the branch picker (top-left of the file list) shows the default.',
        ]
    );

    setup_field(
        'subdir', 'Subdirectory in the repo',
        $subdir, '.', false,
        [
            'Folder inside the repo whose contents will replace the directory <code>pull.php</code> lives in.',
            'Use <code>.</code> to mirror the entire repo root. Use a path like <code>website/public</code> to mirror just that folder.',
        ]
    );

    setup_field(
        'gh_token', 'GitHub Personal Access Token',
        $ghToken, 'github_pat_... or ghp_...', true,
        [
            '<strong>Required for private repos.</strong> Public repos: leave empty.',
            'Where to create — <em>fine-grained PAT</em> (recommended):',
            '&nbsp;&nbsp;1. Open <code>github.com/settings/personal-access-tokens/new</code>',
            '&nbsp;&nbsp;2. Repository access &rarr; "Only select repositories" &rarr; pick this repo',
            '&nbsp;&nbsp;3. Repository permissions &rarr; <strong>Contents: Read</strong>',
            '&nbsp;&nbsp;4. Generate, copy the <code>github_pat_...</code> string, paste it here.',
            'Where to create — <em>classic PAT</em>:',
            '&nbsp;&nbsp;1. Open <code>github.com/settings/tokens/new</code>',
            '&nbsp;&nbsp;2. Tick the full <code>repo</code> scope (not just <code>public_repo</code>)',
            '&nbsp;&nbsp;3. Generate, copy the <code>ghp_...</code> string, paste it here.',
        ]
    );

    setup_field(
        'timezone', 'Timezone',
        $timezone, 'UTC', false,
        [
            'IANA timezone name. Used for the timestamps in the run banner.',
            'Common values: <code>UTC</code>, <code>Europe/Moscow</code>, <code>Europe/London</code>, <code>America/New_York</code>, <code>Asia/Tokyo</code>.',
            'Full list: <code>php.net/manual/en/timezones.php</code>.',
        ]
    );

    setup_field(
        'keep_files', 'Files to preserve',
        $keep, 'pull.php, pull-config.php', false,
        [
            'Comma- or space-separated top-level filenames in this directory that must NOT be overwritten on pull.',
            '<code>pull.php</code> and <code>pull-config.php</code> are always preserved automatically — list any other names here.',
            'Examples: <code>.htaccess</code>, <code>index.html</code>, uploaded media folder names.',
        ],
        true
    );

    echo "<button type=\"submit\" class=\"btn\">$ save_config</button>";
    echo "</form>";
    echo "</div>"; // .term
    echo "<script>window.__intro = {$introJson};</script>";
    echo terminal_js(true);
    echo "</body></html>";
}

function setup_field(string $name, string $label, string $value, string $placeholder, bool $password, array $help, bool $textarea = false): void {
    echo "<div class=\"fld\">";
    echo "<label for=\"f_{$name}\"><span class=\"prompt\">&gt;</span> {$label}</label>";
    echo "<div class=\"help\">";
    foreach ($help as $h) echo "<div>" . $h . "</div>";
    echo "</div>";
    if ($textarea) {
        echo "<textarea id=\"f_{$name}\" name=\"{$name}\" rows=\"2\" placeholder=\"{$placeholder}\">{$value}</textarea>";
    } else {
        $type = $password ? 'password' : 'text';
        $auto = $password ? ' autocomplete="new-password"' : '';
        echo "<input id=\"f_{$name}\" name=\"{$name}\" type=\"{$type}\" placeholder=\"{$placeholder}\" value=\"{$value}\"{$auto}>";
    }
    echo "</div>";
}

function render_setup_done(array $config): void {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<title>pull.php — setup complete</title>";
    echo terminal_css();
    echo "</head><body><div class=\"term\"><div class=\"bar\">";
    echo "<span class=\"dot r\"></span><span class=\"dot y\"></span><span class=\"dot g\"></span>";
    echo "<span class=\"title\">pull.php — setup complete</span>";
    echo "<a class=\"home\" href=\"/\" title=\"Go to site root\">⌂ site root</a></div>";
    echo "<pre class=\"out\">";
    echo "$ pull-config.php written\n";
    echo "  repo:     " . $h($config['repo']) . "\n";
    echo "  branch:   " . $h($config['branch']) . "\n";
    echo "  subdir:   " . $h($config['subdir']) . "\n";
    echo "  timezone: " . $h($config['timezone']) . "\n\n";
    echo "# delete pull-config.php to re-run setup.\n";
    echo "</pre>";
    echo "<a class=\"btn\" href=\"pull.php\">$ run_pull_now</a>";
    echo "</div></body></html>";
}

// ---------- CSS / JS ----------

function terminal_css(): string {
    return <<<CSS
<style>
:root{
  --bg:#2b2b2b;--bg2:#1f1f1f;--bar:#3a3a3a;--fg:#e6e6e6;--mute:#9aa0a6;
  --green:#7CFFB2;--cyan:#5fd7ff;--yellow:#ffd866;--red:#ff6b6b;--accent:#7CFFB2;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg2);color:var(--fg);
  font-family:'JetBrains Mono','Fira Code',Menlo,Consolas,'Courier New',monospace;
  font-size:14px;line-height:1.55}
.term{max-width:880px;margin:2rem auto;background:var(--bg);
  border:1px solid #444;border-radius:8px;
  box-shadow:0 8px 28px rgba(0,0,0,.45);overflow:hidden}
.bar{display:flex;align-items:center;gap:6px;background:var(--bar);
  padding:8px 12px;border-bottom:1px solid #4a4a4a}
.dot{width:12px;height:12px;border-radius:50%}
.dot.r{background:#ff5f56}.dot.y{background:#ffbd2e}.dot.g{background:#27c93f}
.bar .title{margin-left:10px;color:#cfcfcf;font-size:12px;letter-spacing:.2px}
.bar .home{margin-left:auto;color:var(--accent);text-decoration:none;font-size:12px;
  border:1px solid var(--accent);border-radius:4px;padding:2px 8px;white-space:nowrap}
.bar .home:hover{background:var(--accent);color:#1a1a1a}
.out{margin:0;padding:18px 20px;white-space:pre-wrap;word-break:break-word;
  color:var(--fg);min-height:60px}
.out .line{display:block;white-space:pre-wrap}
.out .line::before{content:""}
.out .line.is-cmd{color:var(--accent)}
.out .line.is-err{color:var(--red)}
.out .line.is-cmt{color:var(--mute)}
.out .line.is-info{color:var(--cyan)}
.out .caret{display:inline-block;width:8px;height:1em;
  background:var(--accent);vertical-align:-2px;
  animation:blink 1s steps(1) infinite}
@keyframes blink{50%{opacity:0}}
.form{padding:6px 20px 22px}
.form.hidden{display:none}
.fld{margin:18px 0}
.fld label{display:block;color:var(--accent);font-weight:600;margin-bottom:4px}
.fld .prompt{color:var(--cyan);margin-right:6px}
.fld .help{color:var(--mute);font-size:12.5px;margin:2px 0 8px;padding-left:18px}
.fld .help code{background:#1a1a1a;padding:1px 6px;border-radius:3px;color:#d7d7d7}
.fld input,.fld textarea{
  width:100%;background:#1a1a1a;color:var(--fg);
  border:1px solid #4a4a4a;border-radius:4px;
  padding:9px 11px;font:inherit;outline:none;caret-color:var(--accent)}
.fld input:focus,.fld textarea:focus{border-color:var(--accent)}
.fld textarea{resize:vertical}
.btn{display:inline-block;margin:8px 20px 22px;padding:10px 18px;
  background:#1a1a1a;color:var(--accent);
  border:1px solid var(--accent);border-radius:4px;
  font:inherit;cursor:pointer;text-decoration:none}
.btn:hover{background:var(--accent);color:#1a1a1a}
</style>
CSS;
}

function terminal_js(bool $isForm = false): string {
    if ($isForm) {
        // Setup form: type the intro lines, then reveal the form.
        return <<<'JS'
<script>
(function(){
  const intro = window.__intro || [];
  const host  = document.getElementById('intro');
  const form  = document.getElementById('setup');
  let speed = 7, skipped = false;

  function skip(){ skipped = true; speed = 0; }
  document.addEventListener('click', skip, {once:false});
  document.addEventListener('keydown', skip, {once:false});

  async function typeLine(node, text){
    for (let i = 0; i < text.length; i++){
      node.append(text[i]);
      if (!skipped) await new Promise(r => setTimeout(r, speed));
    }
  }

  async function run(){
    for (const [kind, text] of intro){
      const line = document.createElement('span');
      line.className = 'line' +
        (kind === 'c' ? ' is-cmt' :
         kind === 'e' ? ' is-err' :
         kind === 'p' ? ' is-cmd' :
         kind === 'i' ? ' is-info' : '');
      host.appendChild(line);
      await typeLine(line, text);
      host.appendChild(document.createTextNode('\n'));
    }
    form.classList.remove('hidden');
    const first = form.querySelector('input,textarea');
    if (first) first.focus();
  }
  run();
})();
</script>
JS;
    }

    // Run-output page: type out streaming lines as they arrive from the server.
    return <<<'JS'
<script>
(function(){
  const out = document.getElementById('out');
  if (!out) return;
  const queue = [];
  let busy = false, speed = 4;

  function classify(t){
    const s = t.trim();
    if (!s) return '';
    if (s.startsWith('error:') || s.startsWith('!')) return ' is-err';
    if (s.startsWith('warning:')) return ' is-err';
    if (s.startsWith('#'))   return ' is-cmt';
    if (s.startsWith('==='))  return ' is-cmd';
    if (/^(START|END|STATUS|DURATION|FILES):/.test(s)) return ' is-info';
    if (s.startsWith('downloading') || s.startsWith('downloaded') ||
        s.startsWith('extracted')   || s.startsWith('copied')     ||
        s.startsWith('copying'))    return ' is-info';
    return '';
  }

  async function drain(){
    busy = true;
    while (queue.length){
      const {text, cls} = queue.shift();
      const node = document.createElement('span');
      node.className = 'line' + cls;
      out.appendChild(node);
      for (let i = 0; i < text.length; i++){
        node.append(text[i]);
        if (speed) await new Promise(r => setTimeout(r, speed));
      }
      out.appendChild(document.createTextNode('\n'));
      window.scrollTo(0, document.body.scrollHeight);
      if (queue.length > 30) speed = 0;
      else if (queue.length > 8) speed = 1;
      else speed = 4;
    }
    busy = false;
    if (window.__pullDone){
      const caret = document.createElement('span');
      caret.className = 'caret';
      out.appendChild(caret);
    }
  }

  function enqueue(node){
    queue.push({text: node.dataset.t || '', cls: classify(node.dataset.t || '')});
    node.remove();
    if (!busy) drain();
  }

  // Process anything already in the DOM, then watch for more.
  document.querySelectorAll('.line').forEach(enqueue);
  const obs = new MutationObserver(muts => {
    for (const m of muts) for (const n of m.addedNodes){
      if (n.nodeType === 1 && n.tagName === 'I' && n.classList.contains('line')){
        enqueue(n);
      }
    }
  });
  obs.observe(document.body, {childList:true, subtree:true});

  // Periodically poll in case MutationObserver misses chunks.
  setInterval(() => {
    document.querySelectorAll('i.line').forEach(enqueue);
    if (window.__pullDone && !busy && queue.length === 0){
      // already finished
    }
  }, 250);
})();
</script>
JS;
}
