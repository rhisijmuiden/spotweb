<?php
    require_once __DIR__.'/includes/header.inc.php';
    require_once __DIR__.'/includes/filters.inc.php';

    $ov = $dashboard['overview'];
    $charts = $dashboard['charts'];

    // the database numbers weekdays 0 (Sunday) to 6; show Monday first
    $weekdayNames = [1 => _('Mon'), 2 => _('Tue'), 3 => _('Wed'), 4 => _('Thu'), 5 => _('Fri'), 6 => _('Sat'), 0 => _('Sun')];
    $monthNames = [1 => _('Jan'), 2 => _('Feb'), 3 => _('Mar'), 4 => _('Apr'), 5 => _('May'), 6 => _('Jun'), 7 => _('Jul'), 8 => _('Aug'), 9 => _('Sep'), 10 => _('Oct'), 11 => _('Nov'), 12 => _('Dec')];

    $series = function ($data, $labels) {
        $out = [];
        foreach ($labels as $key => $label) {
            $out[] = ['label' => (string) $label, 'value' => (float) ($data[$key] ?? 0)];
        } // foreach

        return $out;
    };

    $hours = [];
    for ($h = 0; $h < 24; $h++) {
        $hours[$h] = sprintf('%02d', $h);
    } // for

    $categories = [];
    foreach ([0, 1, 2, 3] as $cat) {
        $categories[$cat] = SpotCategories::HeadCat2Desc($cat);
    } // foreach

    // date names in the visitor's language without the deprecated strftime()
    $dayNames = [_('Sunday'), _('Monday'), _('Tuesday'), _('Wednesday'), _('Thursday'), _('Friday'), _('Saturday')];
    $longMonths = [1 => _('January'), _('February'), _('March'), _('April'), _('May'), _('June'), _('July'), _('August'), _('September'), _('October'), _('November'), _('December')];
    $longDate = function ($stamp) use ($dayNames, $longMonths) {
        return $dayNames[(int) date('w', $stamp)].' '.date('j', $stamp).' '.$longMonths[(int) date('n', $stamp)];
    };

    $perDay = [];
    foreach ($dashboard['perday'] as $day) {
        $perDay[] = ['label' => date('j M', $day['stamp']), 'value' => (int) $day['amount'], 'long' => $longDate($day['stamp'])];
    } // foreach

    $jsonData = [
        'perday'   => $perDay,
        'category' => $series($charts['spotspercategory'] ?? [], $categories),
        'hour'     => $series($charts['spotsperhour'] ?? [], $hours),
        'weekday'  => $series($charts['spotsperweekday'] ?? [], $weekdayNames),
        'month'    => (isset($charts['spotspermonth']) && in_array($dashboard['limit'], ['', 'year'], true)) ? $series($charts['spotspermonth'], $monthNames) : null,
        'strings'  => ['spots' => _('spots'), 'average' => _('average')],
    ];

    $fmt = function ($n) { return number_format((float) $n, 0, ',', '.'); };
    $ageDays = $ov['oldest'] ? (int) floor((time() - $ov['oldest']) / 86400) : 0;
?>
<div id="statistics" class="sw-stats">
	<div class="sw-stats-head">
		<div>
			<h1><?php echo _('Statistics'); ?></h1>
			<p class="sw-stats-sub"><?php echo sprintf(_('Updated after every retrieve. Last retrieve: %s'), $ov['updated'] ? $tplHelper->formatDate($ov['updated'], 'force_spotlist') : '–'); ?></p>
		</div>
		<nav class="sw-segmented" aria-label="<?php echo _('Period'); ?>">
<?php foreach ($dashboard['limits'] as $limitValue => $limitName) { ?>
			<a href="<?php echo $tplHelper->makeBaseUrl('path'); ?>?page=statistics&amp;limit=<?php echo urlencode($limitValue); ?>"<?php echo ($limitValue === $dashboard['limit']) ? ' class="active" aria-current="page"' : ''; ?>><?php echo htmlspecialchars(ucfirst($limitName)); ?></a>
<?php } ?>
		</nav>
	</div>

	<div class="sw-kpis">
		<div class="sw-kpi"><span class="sw-kpi-label"><?php echo _('Spots in database'); ?></span><span class="sw-kpi-value"><?php echo $fmt($ov['total']); ?></span></div>
		<div class="sw-kpi"><span class="sw-kpi-label"><?php echo _('Last 24 hours'); ?></span><span class="sw-kpi-value"><?php echo $fmt($ov['day']); ?></span></div>
		<div class="sw-kpi"><span class="sw-kpi-label"><?php echo _('Last 7 days'); ?></span><span class="sw-kpi-value"><?php echo $fmt($ov['week']); ?></span><span class="sw-kpi-note"><?php echo sprintf(_('%s per day'), $fmt($ov['week'] / 7)); ?></span></div>
		<div class="sw-kpi"><span class="sw-kpi-label"><?php echo _('Oldest spot'); ?></span><span class="sw-kpi-value sw-kpi-date"><?php echo $ov['oldest'] ? date('j M Y', $ov['oldest']) : '–'; ?></span><span class="sw-kpi-note"><?php echo sprintf(_('%d days of spots'), $ageDays); ?></span></div>
	</div>

	<section class="sw-card sw-card-wide">
		<header><h2><?php echo _('New spots per day'); ?></h2><span class="sw-card-note"><?php echo _('Last 30 days'); ?></span></header>
		<div class="sw-chart" data-sw-chart="perday" data-sw-kind="columns"></div>
	</section>

	<div class="sw-card-grid">
		<section class="sw-card">
			<header><h2><?php echo _('Spots per category'); ?></h2><span class="sw-card-note"><?php echo htmlspecialchars(ucfirst($dashboard['limits'][$dashboard['limit']])); ?></span></header>
			<div class="sw-chart" data-sw-chart="category" data-sw-kind="hbars"></div>
		</section>
		<section class="sw-card">
			<header><h2><?php echo _('Spots per hour'); ?></h2><span class="sw-card-note"><?php echo htmlspecialchars(ucfirst($dashboard['limits'][$dashboard['limit']])); ?></span></header>
			<div class="sw-chart" data-sw-chart="hour" data-sw-kind="columns"></div>
		</section>
		<section class="sw-card">
			<header><h2><?php echo _('Spots per weekday'); ?></h2><span class="sw-card-note"><?php echo htmlspecialchars(ucfirst($dashboard['limits'][$dashboard['limit']])); ?></span></header>
			<div class="sw-chart" data-sw-chart="weekday" data-sw-kind="columns"></div>
		</section>
<?php if ($jsonData['month'] !== null) { ?>
		<section class="sw-card">
			<header><h2><?php echo _('Spots per month'); ?></h2><span class="sw-card-note"><?php echo htmlspecialchars(ucfirst($dashboard['limits'][$dashboard['limit']])); ?></span></header>
			<div class="sw-chart" data-sw-chart="month" data-sw-kind="columns"></div>
		</section>
<?php } ?>
	</div>
</div>
<script type="application/json" id="sw-stats-data"><?php echo json_encode($jsonData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
<script src="<?php echo htmlspecialchars($tplHelper->getThemeAssetPath('js/stats.js'), ENT_QUOTES); ?>?v=<?php echo (int) @filemtime(__DIR__.'/js/stats.js'); ?>" defer></script>
<?php
    require_once __DIR__.'/includes/footer.inc.php';
