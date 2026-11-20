<?php
declare(strict_types=1);

date_default_timezone_set('America/Los_Angeles');

const SITE = 'https://songtianxiang.com';
const CANONICAL = SITE . '/dice/';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Faces that were rolled but not kept, counted as a multiset.
 * array_diff() is wrong here: rolling [4,4] and keeping one 4 must drop exactly one 4.
 */
function dropped_faces(array $rolls, array $kept): array
{
    $pool = array_values($kept);
    $dropped = [];
    foreach ($rolls as $face) {
        $index = array_search($face, $pool, true);
        if ($index === false) {
            $dropped[] = $face;
        } else {
            unset($pool[$index]);
        }
    }
    return $dropped;
}

/**
 * Parse and roll a dice expression such as:
 *   1d20, d20, 2d6+3, 4d6kh3, 1d8+1d6-1, 2d20kl1
 * Returns ['total' => int, 'parts' => [describe...]] or null when invalid.
 */
function roll_expression(string $expression): ?array
{
    $clean = strtolower(preg_replace('/\s+/', '', $expression) ?? '');
    if ($clean === '' || strlen($clean) > 80) {
        return null;
    }
    // Whole string must be made of valid terms only.
    if (!preg_match('/^[0-9d+\-khl]*$/', $clean)) {
        return null;
    }

    preg_match_all('/([+-]?)(?:(\d*)d(\d+)(?:k([hl])(\d+))?|(\d+))/', $clean, $matches, PREG_SET_ORDER);
    if ($matches === []) {
        return null;
    }

    $rebuilt = '';
    foreach ($matches as $m) {
        $rebuilt .= $m[0];
    }
    if (ltrim($rebuilt, '+') !== ltrim($clean, '+')) {
        return null;
    }

    $total = 0;
    $parts = [];

    foreach ($matches as $m) {
        $sign = ($m[1] ?? '') === '-' ? -1 : 1;

        if (empty($m[3])) { // flat modifier: no "d" in this term
            $value = (int) $m[6];
            $total += $sign * $value;
            $parts[] = ['text' => ($sign < 0 ? '-' : '+') . $value, 'rolls' => [], 'kept' => [], 'value' => $sign * $value, 'sides' => 0];
            continue;
        }

        $count = ($m[2] ?? '') === '' ? 1 : (int) $m[2];
        $sides = (int) $m[3];
        if ($count < 1 || $count > 100 || $sides < 2 || $sides > 1000) {
            return null;
        }

        $rolls = [];
        for ($i = 0; $i < $count; $i++) {
            $rolls[] = random_int(1, $sides);
        }

        $kept = $rolls;
        $keepMode = $m[4] ?? '';
        $keepCount = $keepMode !== '' && isset($m[5]) && $m[5] !== '' ? (int) $m[5] : null;

        if ($keepMode !== '' && $keepCount !== null && ($keepCount < 1 || $keepCount > $count)) {
            // "4d6kh5" cannot mean anything: you cannot keep more dice than you rolled.
            return null;
        }

        if ($keepMode !== '' && $keepCount !== null) {
            $sorted = $rolls;
            sort($sorted);
            if ($keepMode === 'h') {
                $kept = array_slice($sorted, -$keepCount);
            } else {
                $kept = array_slice($sorted, 0, $keepCount);
            }
        }

        $sum = array_sum($kept);
        $total += $sign * $sum;

        $label = $count . 'd' . $sides;
        if ($keepMode !== '') {
            $label .= 'k' . $keepMode . $keepCount;
        }
        $parts[] = [
            'text' => ($sign < 0 ? '-' : '') . $label,
            'rolls' => $rolls,
            'kept' => array_values($kept),
            'value' => $sign * $sum,
            'sides' => $sides,
        ];
    }

    return ['total' => $total, 'parts' => $parts, 'clean' => $clean];
}

$expression = isset($_GET['roll']) ? trim((string) $_GET['roll']) : '';
$result = $expression !== '' ? roll_expression($expression) : null;
$showResult = $result !== null;

// The server also rolls for no-JS visitors and for shared links, so a rejected expression has to
// explain itself instead of silently re-rendering an empty result panel.
$error = $expression !== '' && $result === null
    ? 'That is not a valid dice expression — try 2d6+3, 4d6kh3, or 1d20+5.'
    : '';

$crit = '';
if ($result !== null) {
    $d20kept = [];
    foreach ($result['parts'] as $part) {
        if (($part['sides'] ?? 0) === 20) {
            foreach ($part['kept'] as $face) {
                $d20kept[] = $face;
            }
        }
    }
    if ($d20kept !== []) {
        if (in_array(20, $d20kept, true)) {
            $crit = 'hit';
        } elseif (count(array_filter($d20kept, static fn($v) => $v === 1)) === count($d20kept)) {
            $crit = 'miss';
        }
    }
}

$title = 'D&D Dice Roller — d20, Advantage & Dice Notation';
$description = 'A fast, fair dice roller for D&D and other tabletop RPGs. Roll d4–d100, use advantage and disadvantage, keep the highest (4d6 drop lowest), and read any dice notation like 2d6+3.';

$diceTable = [
    ['d4', 4, '2.5', 'Daggers, darts, Magic Missile'],
    ['d6', 6, '3.5', 'Short swords, Fireball damage, sneak attack'],
    ['d8', 8, '4.5', 'Longswords, Cure Wounds'],
    ['d10', 10, '5.5', 'Glaives, Toll the Dead'],
    ['d12', 12, '6.5', 'Greataxes, Barbarian hit dice'],
    ['d20', 20, '10.5', 'Attack rolls, ability checks, saving throws'],
    ['d100', 100, '50.5', 'Wild magic surges, percentile rolls'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($title) ?></title>
  <meta name="description" content="<?= h($description) ?>">
  <meta name="robots" content="<?= $showResult ? 'noindex, follow' : 'index, follow, max-image-preview:large' ?>">
  <link rel="canonical" href="<?= CANONICAL ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" href="/icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/icon.png">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Song Tianxiang">
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:url" content="<?= CANONICAL ?>">
  <meta property="og:image" content="<?= SITE ?>/og.png">
  <meta property="og:locale" content="en_CA">

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "D&D Dice Roller",
    "url": <?= json_encode(CANONICAL) ?>,
    "applicationCategory": "GameApplication",
    "operatingSystem": "Any",
    "browserRequirements": "Requires JavaScript for instant rolls; server-side rolls work without it.",
    "description": <?= json_encode($description, JSON_UNESCAPED_SLASHES) ?>,
    "offers": { "@type": "Offer", "price": "0", "priceCurrency": "CAD" },
    "isAccessibleForFree": true,
    "author": { "@type": "Person", "name": "Song Tianxiang", "url": "https://songtianxiang.com/" }
  }
  </script>

  <style>
    /* ---------- parchment & brass ---------- */
    :root {
      --ink: #241a10;
      --ink-soft: #5b4a35;
      --parch: #f3e6cc;
      --parch-2: #e8d7b4;
      --edge: #b08d57;
      --brass: #8a6a2c;
      --gold: #c9a227;
      --gold-lt: #f0d583;
      --blood: #8c2f1e;
      --night: #0c0f15;
      --serif: "Iowan Old Style", "Palatino Linotype", Palatino, "Book Antiqua", Georgia, "Times New Roman", serif;
    }
    * { box-sizing: border-box; }
    html { background: var(--night); }
    body {
      margin: 0;
      color: var(--ink);
      font-family: var(--serif);
      font-size: 17px;
      line-height: 1.65;
      background:
        radial-gradient(1200px 600px at 50% -10%, #1b2333 0%, transparent 60%),
        radial-gradient(900px 500px at 80% 110%, #16202c 0%, transparent 55%),
        var(--night);
      background-attachment: fixed;
    }
    a { color: var(--brass); text-decoration: none; border-bottom: 1px solid rgba(138,106,44,.35); }
    a:hover { color: var(--ink); border-bottom-color: var(--ink); }

    /* noise overlay */
    body::before {
      content: "";
      position: fixed; inset: 0; pointer-events: none; z-index: 0; opacity: .05;
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='120' height='120'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3'/></filter><rect width='120' height='120' filter='url(%23n)'/></svg>");
    }

    header.site {
      position: relative; z-index: 1;
      padding: 2.2rem 1.25rem 2.6rem;
      border-bottom: 2px solid var(--edge);
      box-shadow: 0 1px 0 rgba(240,213,131,.25), 0 18px 40px rgba(0,0,0,.45);
      background: linear-gradient(180deg, #121722 0%, #0d1119 100%);
    }
    header.site .wrap { max-width: 980px; margin: 0 auto; }
    header.site .back {
      color: #a9926a; font-size: .74rem; letter-spacing: .18em; text-transform: uppercase;
      border: none;
    }
    header.site .back:hover { color: var(--gold-lt); }

    .crest {
      display: flex; align-items: baseline; gap: .7rem; margin: .8rem 0 .3rem;
    }
    .crest .orn { color: var(--gold); font-size: 1.1rem; opacity: .85; }
    header.site h1 {
      margin: 0;
      font-size: clamp(1.9rem, 4.2vw, 2.7rem);
      font-weight: 600;
      letter-spacing: .02em;
      color: var(--gold-lt);
      text-shadow: 0 1px 0 #000, 0 0 26px rgba(201,162,39,.28);
    }
    header.site p.lede {
      margin: .55rem 0 0; color: #b7ab93; max-width: 46rem; font-size: 1rem;
    }
    header.site p.lede code, .panel code, footer.site code {
      background: rgba(0,0,0,.35); color: var(--gold-lt); border-radius: 4px; padding: .05rem .35rem;
      font-size: .92em;
    }

    main { position: relative; z-index: 1; max-width: 980px; margin: 2rem auto 3.5rem; padding: 0 1.25rem; }
    section.block { margin-top: 1.6rem; }
    h2 {
      color: var(--parch); font-size: 1.2rem; font-weight: 600; letter-spacing: .03em;
      margin: 0 0 .8rem; display: flex; align-items: center; gap: .6rem;
    }
    h2::after {
      content: ""; flex: 1; height: 1px;
      background: linear-gradient(90deg, rgba(176,141,87,.55), transparent);
    }

    .panel {
      position: relative;
      background:
        linear-gradient(180deg, rgba(255,255,255,.35), transparent 30%),
        radial-gradient(120% 90% at 50% 0%, #f8eeda 0%, var(--parch) 45%, var(--parch-2) 100%);
      border: 1px solid var(--edge);
      border-radius: 6px;
      padding: 1.25rem 1.3rem;
      box-shadow:
        inset 0 0 0 3px rgba(255,255,255,.35),
        inset 0 0 0 4px rgba(176,141,87,.45),
        0 18px 36px rgba(0,0,0,.45);
    }
    .panel::before, .panel::after {
      content: "✦"; position: absolute; color: var(--edge); font-size: .85rem; opacity: .7;
    }
    .panel::before { top: .45rem; left: .6rem; }
    .panel::after { bottom: .45rem; right: .6rem; }

    /* ---------- result ---------- */
    .result { text-align: center; padding: 1.5rem .8rem 1.2rem; border-bottom: 1px dashed rgba(138,106,44,.45); }
    .result .label {
      font-size: .72rem; letter-spacing: .22em; text-transform: uppercase; color: var(--ink-soft);
    }
    .result .total {
      font-size: clamp(3.4rem, 11vw, 5rem); font-weight: 600; line-height: 1.02;
      color: var(--ink); font-variant-numeric: tabular-nums;
      text-shadow: 0 2px 0 rgba(255,255,255,.5);
      transition: transform .18s ease, filter .18s ease, opacity .18s ease;
    }
    .result.rolling .total { filter: blur(3px); transform: scale(.94); opacity: .55; }
    .result .expr { color: var(--ink-soft); font-size: .95rem; margin-top: .35rem; font-variant-numeric: tabular-nums; }
    .result .breakdown { color: var(--ink-soft); font-size: .82rem; margin-top: .3rem; }

    .crit {
      display: none; margin: .55rem auto .1rem; width: fit-content;
      font-size: .8rem; letter-spacing: .16em; text-transform: uppercase; font-weight: 700;
      padding: .3rem .9rem; border-radius: 3px;
    }
    .crit.show { display: block; animation: critpop .35s ease; }
    .crit.hit { color: #4a3405; background: linear-gradient(180deg, var(--gold-lt), var(--gold)); box-shadow: 0 0 22px rgba(201,162,39,.55); }
    .crit.miss { color: #fff4ee; background: linear-gradient(180deg, #a5382a, var(--blood)); box-shadow: 0 0 22px rgba(140,47,30,.45); }
    @keyframes critpop { from { transform: scale(.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }

    /* ---------- dice shapes ---------- */
    .dice-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: .5rem; margin: 1.15rem 0 .4rem; }
    .die {
      position: relative; aspect-ratio: 1; border: 0; cursor: pointer; padding: 0;
      font: 600 .82rem/1 var(--serif); color: #2c2008;
      background: linear-gradient(155deg, #f3dda0 0%, var(--gold) 42%, var(--brass) 100%);
      filter: drop-shadow(0 4px 6px rgba(0,0,0,.4));
      transition: transform .12s ease, filter .18s ease;
      display: grid; place-items: center;
    }
    .die:hover { transform: translateY(-2px); filter: drop-shadow(0 7px 10px rgba(0,0,0,.5)) brightness(1.08); }
    .die:active { transform: translateY(0) scale(.97); }
    .die:focus-visible { outline: 2px solid var(--gold-lt); outline-offset: 3px; }
    .die span { transform: translateY(4%); pointer-events: none; }

    .die[data-die="4"]  { clip-path: polygon(50% 3%, 97% 94%, 3% 94%); }
    .die[data-die="4"] span  { transform: translateY(26%); }
    .die[data-die="6"]  { clip-path: polygon(7% 7%, 93% 7%, 93% 93%, 7% 93%); }
    .die[data-die="8"]  { clip-path: polygon(50% 2%, 98% 50%, 50% 98%, 2% 50%); }
    .die[data-die="10"] { clip-path: polygon(50% 2%, 92% 36%, 50% 98%, 8% 36%); }
    .die[data-die="10"] span { transform: translateY(-14%); }
    .die[data-die="12"] { clip-path: polygon(50% 2%, 97% 36%, 80% 96%, 20% 96%, 3% 36%); }
    .die[data-die="12"] span { transform: translateY(-6%); }
    .die[data-die="20"] { clip-path: polygon(50% 2%, 93% 26%, 93% 74%, 50% 98%, 7% 74%, 7% 26%); }
    .die[data-die="100"]{ clip-path: polygon(50% 2%, 88% 18%, 98% 58%, 50% 98%, 2% 58%, 12% 18%); }
    .die[data-die="100"] span { font-size: .7rem; }

    /* ---------- controls ---------- */
    .controls { display: flex; flex-wrap: wrap; gap: .6rem; align-items: center; }
    .controls label {
      font-size: .72rem; letter-spacing: .14em; text-transform: uppercase; color: var(--ink-soft);
    }
    input[type="text"], input[type="number"], select, textarea {
      font: inherit; font-size: .95rem; padding: .42rem .6rem;
      color: var(--ink); background: #fbf4e4;
      border: 1px solid var(--edge); border-radius: 4px;
      box-shadow: inset 0 1px 2px rgba(120,90,40,.2);
    }
    input[type="text"] { min-width: 9rem; }
    input[type="number"] { width: 6rem; }
    textarea { width: 100%; min-height: 7rem; resize: vertical; }
    button.go {
      font: 600 .95rem var(--serif); letter-spacing: .04em;
      color: var(--gold-lt); background: linear-gradient(180deg, #2b3446, #161c27);
      border: 1px solid var(--edge); border-radius: 4px;
      padding: .48rem 1.1rem; cursor: pointer;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.12), 0 2px 0 #0a0d12;
    }
    button.go:hover { background: linear-gradient(180deg, #35405a, #1b2331); color: #fff; }

    .presets { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: 1rem; }
    .preset {
      font: .86rem var(--serif); cursor: pointer; padding: .32rem .75rem;
      color: var(--ink-soft); background: rgba(255,255,255,.4);
      border: 1px solid rgba(176,141,87,.7); border-radius: 999px;
    }
    .preset:hover { color: var(--ink); background: rgba(201,162,39,.22); border-color: var(--brass); }

    /* ---------- history ---------- */
    .history { list-style: none; padding: 0; margin: .9rem 0 0; font-size: .88rem; }
    .history li { display: flex; gap: .8rem; padding: .32rem 0; border-bottom: 1px dotted rgba(138,106,44,.4); }
    .history li:last-child { border-bottom: none; }
    .history .h-total {
      font-weight: 700; color: var(--blood); min-width: 2.6rem; text-align: right;
      font-variant-numeric: tabular-nums;
    }
    .history .h-expr { color: var(--ink-soft); }

    /* ---------- tabs ---------- */
    .tabs { display: flex; gap: .45rem; margin: 0 0 .9rem; flex-wrap: wrap; }
    .tab {
      font: .9rem var(--serif); padding: .42rem .95rem; cursor: pointer; border-radius: 4px;
      color: #b7ab93; background: rgba(255,255,255,.05); border: 1px solid rgba(176,141,87,.45);
    }
    .tab[aria-selected="true"] {
      color: var(--gold-lt); border-color: var(--gold); background: rgba(201,162,39,.14);
    }
    .tabpanel[hidden] { display: none; }

    /* ---------- tables ---------- */
    table { width: 100%; border-collapse: collapse; font-size: .93rem; }
    th, td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid rgba(138,106,44,.3); }
    th {
      color: var(--ink-soft); font-size: .71rem; letter-spacing: .14em; text-transform: uppercase;
      border-bottom: 1px solid rgba(138,106,44,.6);
    }
    tbody tr:last-child td { border-bottom: none; }
    td.num { font-variant-numeric: tabular-nums; }
    tbody tr:hover { background: rgba(201,162,39,.1); }

    /* ---------- prose ---------- */
    main p { color: var(--ink); }
    section.block > p { color: #c9bda6; }
    section.block > p code { background: rgba(255,255,255,.08); color: var(--gold-lt); }
    .panel p { color: var(--ink); }

    .faq details { border-bottom: 1px solid rgba(138,106,44,.3); padding: .7rem 0; }
    .faq details:last-of-type { border-bottom: none; }
    .faq summary { cursor: pointer; font-weight: 600; color: #e8d9bb; }
    .faq summary::marker { color: var(--gold); }
    .faq details p { color: #c9bda6; margin: .5rem 0 0; }
    .faq details code, .faq p code { background: rgba(255,255,255,.08); color: var(--gold-lt); padding: .05rem .3rem; border-radius: 3px; }

    .msg { font-size: .9rem; color: var(--brass); }
    .msg.err { color: var(--blood); }

    footer.site {
      position: relative; z-index: 1; max-width: 980px; margin: 0 auto 3rem; padding: 1.2rem 1.25rem 0;
      color: #8d8168; font-size: .82rem; border-top: 1px solid rgba(176,141,87,.3);
    }
    footer.site a { color: #b7ab93; border-bottom-color: rgba(176,141,87,.4); }

    @media (max-width: 620px) {
      .dice-grid { grid-template-columns: repeat(4, 1fr); }
      .result .total { font-size: 3.6rem; }
      header.site h1 { font-size: 1.7rem; }
    }
    @media (prefers-reduced-motion: reduce) {
      .result.rolling .total { filter: none; transform: none; opacity: 1; }
      .crit.show { animation: none; }
      .die { transition: none; }
    }
</style>
</head>
<body>

<header class="site">
  <div class="wrap">
    <a class="back" href="/">← Song Tianxiang</a>
    <div class="crest">
      <h1>Dice Roller for D&amp;D</h1>
      <span class="orn" aria-hidden="true">⋈</span>
    </div>
    <p class="lede">Roll any die, with advantage or disadvantage, keep the highest, and parse notation like <code>2d6+3</code> or <code>4d6kh3</code>. Free, no sign-up, and every roll uses a cryptographically secure random source.</p>
  </div>
</header>

<main>

  <section class="block">
    <div class="panel">
      <div class="result" id="result">
<?php if ($showResult): ?>
        <div class="label">Roll result</div>
        <div class="total" id="total"><?= h((string) $result['total']) ?></div>
        <div class="expr" id="expr"><?= h($result['clean']) ?></div>
        <div class="breakdown" id="breakdown">
          <?php
          $bits = [];
          foreach ($result['parts'] as $part) {
              $line = $part['text'] . ' → ' . implode(', ', $part['kept']);
              if ($part['rolls'] !== [] && count($part['kept']) !== count($part['rolls'])) {
                  $dropped = dropped_faces($part['rolls'], $part['kept']);
                  $line .= ' (dropped ' . implode(', ', $dropped) . ')';
              }
              $bits[] = $line;
          }
          echo h(implode('  ·  ', $bits));
          ?>
        </div>
<?php else: ?>
        <div class="label">Ready to roll</div>
        <div class="total" id="total">—</div>
        <div class="expr" id="expr">pick a die below, or type an expression</div>
        <div class="breakdown" id="breakdown"></div>
<?php endif; ?>
        <div class="crit<?= $crit !== '' ? ' show ' . h($crit) : '' ?>" id="crit" role="status" aria-live="polite"><?php
          echo $crit === 'hit' ? 'Natural 20 — critical hit' : ($crit === 'miss' ? 'Natural 1 — critical miss' : '');
        ?></div>
      </div>

      <div class="dice-grid">
        <button class="die" data-die="4">d4</button>
        <button class="die" data-die="6">d6</button>
        <button class="die" data-die="8">d8</button>
        <button class="die" data-die="10">d10</button>
        <button class="die" data-die="12">d12</button>
        <button class="die" data-die="20">d20</button>
        <button class="die" data-die="100">d100</button>
      </div>

      <div class="controls">
        <label for="modifier">Modifier</label>
        <input type="number" id="modifier" value="0" step="1" aria-label="Modifier added to the roll">

        <label for="mode">d20 mode</label>
        <select id="mode" aria-label="Advantage or disadvantage">
          <option value="normal">Normal</option>
          <option value="adv">Advantage (2d20 keep highest)</option>
          <option value="dis">Disadvantage (2d20 keep lowest)</option>
        </select>
      </div>

      <form class="controls" method="get" action="/dice/" style="margin-top:.9rem">
        <label for="roll">Expression</label>
        <input type="text" id="roll" name="roll" placeholder="2d6+3, 4d6kh3, 1d8+1d6" value="<?= h($expression) ?>" autocomplete="off" spellcheck="false">
        <button class="go" type="submit">Roll</button>
        <span class="msg<?= $error !== '' ? ' err' : '' ?>" id="status" role="status" aria-live="polite"><?= h($error) ?></span>
      </form>

      <div class="presets">
        <button class="preset" data-expr="1d20">Ability check</button>
        <button class="preset" data-expr="1d20+5">Check with +5</button>
        <button class="preset" data-expr="2d20kh1">Advantage</button>
        <button class="preset" data-expr="2d20kl1">Disadvantage</button>
        <button class="preset" data-expr="4d6kh3">Stats (4d6 drop lowest)</button>
        <button class="preset" data-expr="2d6+3">Fireball</button>
        <button class="preset" data-expr="1d100">d100</button>
      </div>

      <ul class="history" id="history" aria-label="Roll history"></ul>
    </div>
    <noscript><p class="msg">JavaScript is off, so instant rolls are unavailable — the expression form above still rolls on the server and shows the result in the page.</p></noscript>
  </section>

  <section class="block" id="tabs-section">
    <div class="tabs" role="tablist">
      <button class="tab" role="tab" aria-selected="true" aria-controls="panel-rng" id="tab-rng">Random number</button>
      <button class="tab" role="tab" aria-selected="false" aria-controls="panel-pick" id="tab-pick">Pick from a list</button>
    </div>

    <div class="panel">
      <div class="tabpanel" id="panel-rng" role="tabpanel" aria-labelledby="tab-rng">
        <p style="margin-top:0">For things that are not dice: roll an integer between two numbers, inclusive. Handy for DM fiat, loot tables, or picking a seat.</p>
        <div class="controls">
          <label for="min">Min</label>
          <input type="number" id="min" value="1" step="1">
          <label for="max">Max</label>
          <input type="number" id="max" value="100" step="1">
          <button class="go" id="roll-range" type="button">Roll</button>
          <span class="msg" id="range-out" role="status" aria-live="polite"></span>
        </div>
      </div>

      <div class="tabpanel" id="panel-pick" role="tabpanel" aria-labelledby="tab-pick" hidden>
        <p style="margin-top:0">One entry per line. Names, loot, rumours, random encounters — the roller picks one uniformly.</p>
        <textarea id="pick-list" aria-label="List of options, one per line">Aria
Borin
Cass
Doran
Elowen</textarea>
        <div class="controls" style="margin-top:.7rem">
          <button class="go" id="pick-one" type="button">Pick one</button>
          <span class="msg" id="pick-out" role="status" aria-live="polite"></span>
        </div>
      </div>
    </div>
  </section>

  <section class="block">
    <h2>How to read dice notation</h2>
    <p>Tabletop notation is <code>NdX</code>: roll <em>N</em> dice with <em>X</em> sides. The <em>N</em> is optional and defaults to 1, so <code>d20</code> and <code>1d20</code> are the same roll.</p>
    <div class="panel">
      <table>
        <thead>
          <tr><th>Notation</th><th>Meaning</th><th>Example</th></tr>
        </thead>
        <tbody>
          <tr><td><code>d20</code></td><td>One twenty-sided die</td><td>An attack roll</td></tr>
          <tr><td><code>2d6+3</code></td><td>Two d6, add 3 to the total</td><td>Fireball at 1st level</td></tr>
          <tr><td><code>4d6kh3</code></td><td>Four d6, keep the highest three</td><td>Rolling ability scores</td></tr>
          <tr><td><code>2d20kh1</code></td><td>Two d20, keep the highest</td><td>Advantage</td></tr>
          <tr><td><code>2d20kl1</code></td><td>Two d20, keep the lowest</td><td>Disadvantage</td></tr>
          <tr><td><code>1d8+1d6</code></td><td>Two different dice, summed</td><td>Weapon plus a spell rider</td></tr>
        </tbody>
      </table>
    </div>
  </section>

  <section class="block">
    <h2>The standard dice and their averages</h2>
    <p>The average of a fair dX is <code>(X + 1) / 2</code>. That is the number to use when you are planning a build or estimating damage.</p>
    <div class="panel">
      <table>
        <thead>
          <tr><th>Die</th><th>Sides</th><th>Average</th><th>Typical use</th></tr>
        </thead>
        <tbody>
<?php foreach ($diceTable as [$die, $sides, $avg, $use]): ?>
          <tr>
            <td><strong><?= h($die) ?></strong></td>
            <td class="num"><?= h((string) $sides) ?></td>
            <td class="num"><?= h($avg) ?></td>
            <td><?= h($use) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="block">
    <h2>Advantage and disadvantage, in one paragraph</h2>
    <p>Roll two d20 and take the higher result for advantage, or the lower for disadvantage. Everything else on the roll — the modifier, the target number — stays the same. Advantage raises your average from 10.5 to about 13.8; disadvantage lowers it to about 7.2. Because the two dice are rolled together, advantage is strongest exactly where it matters: near the middle of the difficulty curve.</p>
  </section>

  <section class="block">
    <h2>Is this roller fair?</h2>
    <p>Yes, as far as software can be. The instant roller uses <code>crypto.getRandomValues</code>, and the server-side fallback uses PHP's <code>random_int</code>, both of which draw from the operating system's cryptographically secure generator. Every value in range is equally likely, and dice are rolled independently — no seeding, no "lucky" streaks, no memory between rolls.</p>
  </section>

  <section class="block faq">
    <h2>Questions</h2>
    <details>
      <summary>What does 2d6+3 mean?</summary>
      <p>Roll two six-sided dice, add them together, then add 3. The result is somewhere between 5 and 15, with 10 being the most likely total.</p>
    </details>
    <details>
      <summary>How do I roll with advantage here?</summary>
      <p>Pick <code>2d20kh1</code> — that is two d20 keeping the highest — or set the d20 mode to Advantage and roll a d20. For disadvantage, use <code>2d20kl1</code>.</p>
    </details>
    <details>
      <summary>How do I roll ability scores?</summary>
      <p>Use <code>4d6kh3</code>: four d6, drop the lowest, sum the rest. Roll it six times and assign the numbers to your six abilities.</p>
    </details>
    <details>
      <summary>Can I roll multiple different dice at once?</summary>
      <p>Yes. Expressions like <code>1d8+1d6+2</code> work, and each term is rolled separately before being summed.</p>
    </details>
    <details>
      <summary>Does it work offline or without JavaScript?</summary>
      <p>The instant buttons need JavaScript. The expression box falls back to a server-side roll, so <code>/dice/?roll=2d6+3</code> works even with scripting disabled — and you can bookmark or share that link.</p>
    </details>
  </section>
</main>

<footer class="site">
  <p>Made for my own table. If a roll looks wrong, the expression is shown right above the total so you can check it. <a href="/">songtianxiang.com</a></p>
</footer>

<script>
(function () {
  "use strict";

  // ---------- randomness ----------
  function randInt(max) { // 1..max inclusive, uniform
    if (window.crypto && window.crypto.getRandomValues) {
      const limit = Math.floor(0xFFFFFFFF / max) * max; // rejection sampling
      const buf = new Uint32Array(1);
      let v;
      do {
        window.crypto.getRandomValues(buf);
        v = buf[0];
      } while (v >= limit);
      return (v % max) + 1;
    }
    return Math.floor(Math.random() * max) + 1;
  }

  // ---------- expression parser (mirrors the PHP one) ----------
  function parse(expr) {
    const clean = String(expr).replace(/\s+/g, "").toLowerCase();
    if (!clean || clean.length > 80 || !/^[0-9d+\-khl]+$/.test(clean)) return null;

    const re = /([+-]?)(?:(\d*)d(\d+)(?:k([hl])(\d+))?|(\d+))/g;
    let m, rebuilt = "", parts = [], total = 0;

    while ((m = re.exec(clean)) !== null) {
      rebuilt += m[0];
      const sign = m[1] === "-" ? -1 : 1;

      if (m[3] === undefined) { // flat modifier: no "d" in this term
        const value = parseInt(m[6], 10);
        total += sign * value;
        parts.push({ text: (sign < 0 ? "-" : "+") + value, rolls: [], kept: [], value: sign * value, sides: 0 });
        continue;
      }

      const count = m[2] === "" ? 1 : parseInt(m[2], 10);
      const sides = parseInt(m[3], 10);
      if (count < 1 || count > 100 || sides < 2 || sides > 1000) return null;

      const rolls = [];
      for (let i = 0; i < count; i++) rolls.push(randInt(sides));

      let kept = rolls.slice();
      if (m[4] && m[5] !== undefined) {
        const k = parseInt(m[5], 10);
        // "4d6kh5" means nothing: you cannot keep more dice than you rolled.
        if (k < 1 || k > count) return null;
        const sorted = rolls.slice().sort((a, b) => a - b);
        kept = m[4] === "h" ? sorted.slice(-k) : sorted.slice(0, k);
      }

      const sum = kept.reduce((a, b) => a + b, 0);
      total += sign * sum;
      parts.push({
        text: (sign < 0 ? "-" : "") + count + "d" + sides + (m[4] ? "k" + m[4] + m[5] : ""),
        rolls: rolls,
        kept: kept,
        value: sign * sum,
        sides: sides
      });
    }

    if (rebuilt !== clean) return null;
    return { total: total, parts: parts, clean: clean };
  }

  // ---------- rendering ----------
  const $ = (id) => document.getElementById(id);
  const totalEl = $("total"), exprEl = $("expr"), breakdownEl = $("breakdown"), statusEl = $("status");
  const historyEl = $("history");
  const resultEl = $("result");
  const critEl = $("crit");
  let history = [];

  function detectCrit(r) {
    const faces = r.parts.filter((p) => p.sides === 20).flatMap((p) => p.kept);
    if (!faces.length) return "";
    if (faces.includes(20)) return "hit";
    if (faces.every((v) => v === 1)) return "miss";
    return "";
  }

  // Faces rolled but not kept, as a multiset. A plain filter() drops every copy of a duplicated
  // face: rolling [4,4] and keeping one 4 must report a single dropped 4, not two.
  function droppedFaces(rolls, kept) {
    const pool = kept.slice();
    const dropped = [];
    for (const face of rolls) {
      const i = pool.indexOf(face);
      if (i === -1) dropped.push(face);
      else pool.splice(i, 1);
    }
    return dropped;
  }

  function describe(part) {
    let line = part.text + " → " + part.kept.join(", ");
    if (part.rolls.length && part.kept.length !== part.rolls.length) {
      line += " (dropped " + droppedFaces(part.rolls, part.kept).join(", ") + ")";
    }
    return line;
  }

  function show(result) {
    totalEl.textContent = result.total;
    exprEl.textContent = result.clean;
    breakdownEl.textContent = result.parts.map(describe).join("  ·  ");
    if (statusEl) statusEl.textContent = "";
    const crit = detectCrit(result);
    if (critEl) {
      critEl.textContent = crit === "hit" ? "Natural 20 — critical hit"
        : crit === "miss" ? "Natural 1 — critical miss" : "";
      critEl.className = "crit" + (crit ? " show " + crit : "");
    }
    history.unshift(result);
    history = history.slice(0, 8);
    historyEl.innerHTML = history
      .map((r) => `<li><span class="h-total">${r.total}</span><span class="h-expr">${r.clean}</span></li>`)
      .join("");
  }

  function roll(expr) {
    const result = parse(expr);
    if (!result) {
      if (statusEl) {
        statusEl.textContent = "Not a valid expression — try 2d6+3";
        statusEl.classList.add("err");
      }
      return;
    }
    if (statusEl) statusEl.classList.remove("err");

    const reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduce || !resultEl) { show(result); return; }

    resultEl.classList.add("rolling");
    const ticks = 7;
    let tick = 0;
    const timer = setInterval(() => {
      totalEl.textContent = String(1 + Math.floor(Math.random() * 20));
      if (++tick >= ticks) clearInterval(timer);
    }, 38);
    setTimeout(() => {
      clearInterval(timer);
      resultEl.classList.remove("rolling");
      show(result);
    }, ticks * 38 + 110);
  }

  // ---------- controls ----------
  document.querySelectorAll(".die").forEach((btn) => {
    btn.addEventListener("click", () => {
      const sides = btn.dataset.die;
      const mod = parseInt($("modifier").value || "0", 10) || 0;
      const mode = $("mode").value;
      let expr = "1d" + sides;
      if (sides === "20" && mode !== "normal") {
        expr = mode === "adv" ? "2d20kh1" : "2d20kl1";
      } else if (mod !== 0) {
        expr += (mod > 0 ? "+" : "") + mod;
      }
      roll(expr);
    });
  });

  document.querySelectorAll(".preset").forEach((btn) => {
    btn.addEventListener("click", () => {
      $("roll").value = btn.dataset.expr;
      roll(btn.dataset.expr);
    });
  });

  document.getElementById("roll").addEventListener("keydown", (e) => {
    if (e.key === "Enter") { e.preventDefault(); roll(e.target.value); }
  });

  // ---------- random range ----------
  $("roll-range").addEventListener("click", () => {
    let min = parseInt($("min").value, 10), max = parseInt($("max").value, 10);
    if (!Number.isFinite(min) || !Number.isFinite(max)) { $("range-out").textContent = "Enter two numbers"; return; }
    if (min > max) { const t = min; min = max; max = t; }
    if (max - min > 1000000000) { $("range-out").textContent = "Range too wide"; return; }
    const span = max - min + 1;
    $("range-out").textContent = String(min + randInt(span) - 1);
  });

  // ---------- pick from list ----------
  $("pick-one").addEventListener("click", () => {
    const items = $("pick-list").value.split("\n").map((s) => s.trim()).filter(Boolean);
    if (!items.length) { $("pick-out").textContent = "Add at least one line"; return; }
    $("pick-out").textContent = items[randInt(items.length) - 1];
  });

  // ---------- tabs ----------
  document.querySelectorAll(".tab").forEach((tab) => {
    tab.addEventListener("click", () => {
      document.querySelectorAll(".tab").forEach((t) => t.setAttribute("aria-selected", "false"));
      tab.setAttribute("aria-selected", "true");
      document.getElementById("panel-rng").hidden = tab.id !== "tab-rng";
      document.getElementById("panel-pick").hidden = tab.id !== "tab-pick";
    });
  });
})();
</script>
</body>
</html>
