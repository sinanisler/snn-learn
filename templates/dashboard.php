<?php
global $wpdb;
$table = $wpdb->prefix . 'snn_enrollments';

// KPI Calculations
$total_enrollments = $wpdb->get_var("SELECT COUNT(*) FROM $table");
$completed_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE completed_at IS NOT NULL");
$completion_rate = $total_enrollments > 0 ? round(($completed_count / $total_enrollments) * 100, 1) : 0;

$one_week_ago = time() - (7 * 24 * 60 * 60);
$active_this_week = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM $table WHERE last_activity_at > %d", $one_week_ago));

$two_weeks_ago = time() - (14 * 24 * 60 * 60);
$gone_cold = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE last_activity_at < %d AND completed_at IS NULL", $two_weeks_ago));

// Course Performance
$courses_data = $wpdb->get_results("
    SELECT course_id, COUNT(*) as enrolled, COUNT(completed_at) as completed 
    FROM $table 
    GROUP BY course_id 
    ORDER BY enrolled DESC 
    LIMIT 5
");

// At-Risk Students
$at_risk = $wpdb->get_results($wpdb->prepare("
    SELECT user_id, post_id, last_activity_at 
    FROM $table 
    WHERE completed_at IS NULL AND last_activity_at < %d 
    ORDER BY last_activity_at ASC 
    LIMIT 5
", $two_weeks_ago));

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SNN Education — Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,300;12..96,400;12..96,500;12..96,600;12..96,700&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #F4F3EF;
    --surface:   #FFFFFF;
    --surface2:  #FAFAF8;
    --border:    #E8E6DF;
    --border2:   #D5D2C8;
    --text:      #18181B;
    --text2:     #52525B;
    --text3:     #A1A1AA;
    --accent:    #2563EB;
    --accent-lt: #EFF6FF;
    --accent-dk: #1D4ED8;
    --green:     #059669;
    --green-lt:  #ECFDF5;
    --amber:     #D97706;
    --amber-lt:  #FFFBEB;
    --red:       #DC2626;
    --red-lt:    #FEF2F2;
    --purple:    #7C3AED;
    --purple-lt: #F5F3FF;
    --radius:    10px;
    --radius-lg: 16px;
    --shadow:    0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    --shadow-md: 0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04);
  }

  .snn-learn-dashboard-wrap {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
    min-height: 100vh;
    padding: 28px 32px 40px;
  }

  /* ── KPI STRIP ── */
  .snn-learn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 22px;
  }
  .snn-learn-kpi-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px 22px;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
    transition: box-shadow .2s, transform .2s;
  }
  .snn-learn-kpi-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
  .snn-learn-kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  }
  .snn-learn-kpi-card.blue::before   { background: var(--accent); }
  .snn-learn-kpi-card.green::before  { background: var(--green); }
  .snn-learn-kpi-card.amber::before  { background: var(--amber); }
  .snn-learn-kpi-card.red::before    { background: var(--red); }

  .snn-learn-kpi-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px; }
  .snn-learn-kpi-icon {
    width: 36px; height: 36px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .snn-learn-kpi-icon svg { width: 18px; height: 18px; }
  .snn-learn-kpi-icon.blue   { background: var(--accent-lt); color: var(--accent); }
  .snn-learn-kpi-icon.green  { background: var(--green-lt);  color: var(--green); }
  .snn-learn-kpi-icon.amber  { background: var(--amber-lt);  color: var(--amber); }
  .snn-learn-kpi-icon.red    { background: var(--red-lt);    color: var(--red); }

  .snn-learn-kpi-delta {
    font-size: 11px;
    font-weight: 500;
    padding: 3px 8px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: 3px;
  }
  .snn-learn-kpi-delta.up   { background: var(--green-lt); color: var(--green); }
  .snn-learn-kpi-delta.down { background: var(--red-lt);   color: var(--red); }
  .snn-learn-kpi-delta.neu  { background: var(--bg);        color: var(--text3); }

  .snn-learn-kpi-value {
    font-family: 'Bricolage Grotesque', sans-serif;
    font-size: 32px;
    font-weight: 700;
    color: var(--text);
    line-height: 1;
    margin-bottom: 4px;
    letter-spacing: -1px;
  }
  .snn-learn-kpi-label { font-size: 12.5px; color: var(--text3); font-weight: 400; }
  .snn-learn-kpi-sub {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--border);
    font-size: 11.5px;
    color: var(--text3);
  }
  .snn-learn-kpi-sub strong { color: var(--text2); font-weight: 500; }

  /* ── SPARKLINE ── */
  .snn-learn-sparkline { width: 100%; height: 36px; margin-top: 10px; }

  /* ── TWO COLUMN ── */
  .snn-learn-two-col { display: grid; grid-template-columns: 1fr 380px; gap: 16px; margin-bottom: 16px; }
  .snn-learn-three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }

  /* ── CARD ── */
  .snn-learn-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    overflow: hidden;
  }
  .snn-learn-card-header {
    padding: 18px 22px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
  }
  .snn-learn-card-title {
    font-family: 'Bricolage Grotesque', sans-serif;
    font-size: 14.5px;
    font-weight: 600;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .snn-learn-card-title svg { width: 15px; height: 15px; color: var(--text3); }
  .snn-learn-card-meta { font-size: 12px; color: var(--text3); }
  .snn-learn-card-actions { display: flex; align-items: center; gap: 8px; }
  .snn-learn-card-body { padding: 20px 22px; }
  .snn-learn-card-body-flush { padding: 0; }

  /* ── TABLE ── */
  .snn-learn-table-wrap table { width: 100%; border-collapse: collapse; }
  .snn-learn-table-wrap thead th {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .5px;
    text-transform: uppercase;
    color: var(--text3);
    padding: 10px 22px;
    text-align: left;
    background: var(--surface2);
    border-bottom: 1px solid var(--border);
  }
  .snn-learn-table-wrap thead th:last-child { text-align: right; }
  .snn-learn-table-wrap tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background .1s;
  }
  .snn-learn-table-wrap tbody tr:last-child { border-bottom: none; }
  .snn-learn-table-wrap tbody tr:hover { background: var(--surface2); }
  .snn-learn-table-wrap tbody td {
    padding: 12px 22px;
    font-size: 13.5px;
    color: var(--text2);
    vertical-align: middle;
  }
  .snn-learn-table-wrap tbody td:last-child { text-align: right; }
  .snn-learn-table-wrap tbody td:first-child { color: var(--text); font-weight: 500; }

  /* ── PROGRESS BAR ── */
  .snn-learn-prog-wrap { display: flex; align-items: center; gap: 10px; }
  .snn-learn-prog-bar {
    flex: 1; height: 6px; background: var(--border); border-radius: 99px; overflow: hidden;
  }
  .snn-learn-prog-fill {
    height: 100%; border-radius: 99px;
    background: var(--green);
    transition: width .5s ease;
  }
  .snn-learn-prog-fill.mid { background: var(--amber); }
  .snn-learn-prog-fill.low { background: var(--red); }
  .snn-learn-prog-pct { font-size: 12px; font-weight: 600; color: var(--text); min-width: 30px; text-align: right; }

  /* ── CHIP / BADGE ── */
  .snn-learn-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 500;
    padding: 3px 9px;
    border-radius: 20px;
    white-space: nowrap;
  }
  .snn-learn-chip.green  { background: var(--green-lt);  color: var(--green); }
  .snn-learn-chip.amber  { background: var(--amber-lt);  color: var(--amber); }
  .snn-learn-chip.red    { background: var(--red-lt);    color: var(--red); }
  .snn-learn-chip.blue   { background: var(--accent-lt); color: var(--accent); }
  .snn-learn-chip.gray   { background: var(--bg);        color: var(--text3); }
  .snn-learn-chip::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

  /* ── AVATAR ── */
  .snn-learn-avatar {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--accent-lt);
    color: var(--accent);
    font-size: 11px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    letter-spacing: .3px;
  }
  .snn-learn-avatar-wrap { display: flex; align-items: center; gap: 9px; }
  .snn-learn-avatar-name { font-size: 13.5px; font-weight: 500; color: var(--text); }
  .snn-learn-avatar-email { font-size: 11px; color: var(--text3); }

  /* ── MINI STATS ── */
  .snn-learn-mini-stat-row {
    display: flex;
    gap: 0;
    border-radius: var(--radius-lg);
    overflow: hidden;
    border: 1px solid var(--border);
    margin-bottom: 16px;
    background: var(--surface);
    box-shadow: var(--shadow);
  }
  .snn-learn-mini-stat {
    flex: 1;
    padding: 16px 20px;
    border-right: 1px solid var(--border);
  }
  .snn-learn-mini-stat:last-child { border-right: none; }
  .snn-learn-mini-stat-val {
    font-family: 'Bricolage Grotesque', sans-serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -.5px;
    line-height: 1;
    margin-bottom: 3px;
  }
  .snn-learn-mini-stat-lbl { font-size: 11.5px; color: var(--text3); }

  /* ── CHART AREA ── */
  .snn-learn-chart-area { width: 100%; height: 180px; position: relative; }
  .snn-learn-chart-canvas { width: 100%; height: 100%; }

  /* ── FUNNEL ── */
  .snn-learn-funnel { display: flex; flex-direction: column; gap: 10px; padding: 4px 0; }
  .snn-learn-funnel-row { display: flex; align-items: center; gap: 12px; }
  .snn-learn-funnel-label { font-size: 12px; color: var(--text2); width: 80px; flex-shrink: 0; text-align: right; }
  .snn-learn-funnel-bar-wrap { flex: 1; }
  .snn-learn-funnel-bar {
    height: 28px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    padding: 0 12px;
    font-size: 11.5px;
    font-weight: 600;
    color: white;
    transition: width .6s ease;
  }
  .snn-learn-funnel-count { font-size: 12px; color: var(--text3); width: 50px; text-align: right; flex-shrink: 0; }

  /* ── AT-RISK ── */
  .snn-learn-risk-list { display: flex; flex-direction: column; gap: 0; }
  .snn-learn-risk-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 22px;
    border-bottom: 1px solid var(--border);
    transition: background .1s;
  }
  .snn-learn-risk-item:last-child { border-bottom: none; }
  .snn-learn-risk-item:hover { background: var(--surface2); }
  .snn-learn-risk-days {
    margin-left: auto;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red);
    background: var(--red-lt);
    padding: 3px 8px;
    border-radius: 20px;
    white-space: nowrap;
    flex-shrink: 0;
  }
  .snn-learn-risk-course { font-size: 11px; color: var(--text3); }

  /* ── ACTIVITY FEED ── */
  .snn-learn-feed { display: flex; flex-direction: column; gap: 0; }
  .snn-learn-feed-item {
    display: flex;
    gap: 12px;
    padding: 12px 22px;
    border-bottom: 1px solid var(--border);
    align-items: flex-start;
  }
  .snn-learn-feed-item:last-child { border-bottom: none; }
  .snn-learn-feed-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--accent);
    margin-top: 5px;
    flex-shrink: 0;
  }
  .snn-learn-feed-dot.green { background: var(--green); }
  .snn-learn-feed-dot.amber { background: var(--amber); }
  .snn-learn-feed-text { font-size: 13px; color: var(--text2); line-height: 1.4; flex: 1; }
  .snn-learn-feed-text strong { color: var(--text); font-weight: 500; }
  .snn-learn-feed-time { font-size: 11px; color: var(--text3); margin-top: 2px; }

  /* ── TABS ── */
  .snn-learn-tab-bar {
    display: flex;
    gap: 2px;
    padding: 4px;
    background: var(--bg);
    border-radius: 10px;
    width: fit-content;
  }
  .snn-learn-tab-btn {
    padding: 6px 16px;
    border-radius: 8px;
    border: none;
    background: transparent;
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    font-weight: 400;
    color: var(--text2);
    cursor: pointer;
    transition: all .15s;
  }
  .snn-learn-tab-btn.active {
    background: var(--surface);
    color: var(--text);
    font-weight: 500;
    box-shadow: var(--shadow);
  }

  /* ── SEARCH ── */
  .snn-learn-search-wrap { position: relative; }
  .snn-learn-search-wrap input {
    width: 220px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 7px 12px 7px 32px;
    font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    outline: none;
    transition: border-color .15s, background .15s;
  }
  .snn-learn-search-wrap input:focus { border-color: var(--accent); background: var(--surface); }
  .snn-learn-search-wrap svg {
    position: absolute;
    left: 10px; top: 50%;
    transform: translateY(-50%);
    width: 14px; height: 14px;
    color: var(--text3);
    pointer-events: none;
  }

  /* ── COURSE DOT ── */
  .snn-learn-course-dot {
    width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; flex-shrink: 0;
  }

  /* ── ANIMATIONS ── */
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .snn-learn-kpi-card { animation: fadeUp .35s ease both; }
  .snn-learn-kpi-card:nth-child(1) { animation-delay: .05s; }
  .snn-learn-kpi-card:nth-child(2) { animation-delay: .10s; }
  .snn-learn-kpi-card:nth-child(3) { animation-delay: .15s; }
  .snn-learn-kpi-card:nth-child(4) { animation-delay: .20s; }
  .snn-learn-card { animation: fadeUp .4s ease both; animation-delay: .25s; }
</style>
</head>
<body>

  <div class="snn-learn-dashboard-wrap">

    <!-- ── KPI CARDS ── -->
    <div class="snn-learn-kpi-grid">
      <!-- Total Enrolled -->
      <div class="snn-learn-kpi-card blue">
        <div class="snn-learn-kpi-top">
          <div class="snn-learn-kpi-icon blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
          </div>
          <div class="snn-learn-kpi-delta up">↑ 12%</div>
        </div>
        <div class="snn-learn-kpi-value" id="kpi-total"><?php echo number_format($total_enrollments); ?></div>
        <div class="snn-learn-kpi-label">Total Enrollments</div>
        <div class="snn-learn-kpi-sub"><strong>+47</strong> in the last 30 days</div>
        <svg class="snn-learn-sparkline" id="spark-total" viewBox="0 0 200 36" preserveAspectRatio="none"></svg>
      </div>

      <!-- Completion Rate -->
      <div class="snn-learn-kpi-card green">
        <div class="snn-learn-kpi-top">
          <div class="snn-learn-kpi-icon green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <div class="snn-learn-kpi-delta up">↑ 4%</div>
        </div>
        <div class="snn-learn-kpi-value" id="kpi-completion"><?php echo $completion_rate; ?>%</div>
        <div class="snn-learn-kpi-label">Completion Rate</div>
        <div class="snn-learn-kpi-sub"><strong><?php echo number_format($completed_count); ?></strong> of <?php echo number_format($total_enrollments); ?> finished</div>
        <svg class="snn-learn-sparkline" id="spark-completion" viewBox="0 0 200 36" preserveAspectRatio="none"></svg>
      </div>

      <!-- Active This Week -->
      <div class="snn-learn-kpi-card amber">
        <div class="snn-learn-kpi-top">
          <div class="snn-learn-kpi-icon amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          </div>
          <div class="snn-learn-kpi-delta neu">→ 0%</div>
        </div>
        <div class="snn-learn-kpi-value" id="kpi-active"><?php echo number_format($active_this_week); ?></div>
        <div class="snn-learn-kpi-label">Active This Week</div>
        <div class="snn-learn-kpi-sub"><strong><?php echo $total_enrollments > 0 ? round(($active_this_week / $total_enrollments) * 100, 1) : 0; ?>%</strong> of enrolled students</div>
        <svg class="snn-learn-sparkline" id="spark-active" viewBox="0 0 200 36" preserveAspectRatio="none"></svg>
      </div>

      <!-- Cold / At-Risk -->
      <div class="snn-learn-kpi-card red">
        <div class="snn-learn-kpi-top">
          <div class="snn-learn-kpi-icon red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </div>
          <div class="snn-learn-kpi-delta down">↑ 8</div>
        </div>
        <div class="snn-learn-kpi-value" id="kpi-cold"><?php echo number_format($gone_cold); ?></div>
        <div class="snn-learn-kpi-label">Gone Cold</div>
        <div class="snn-learn-kpi-sub">No activity for <strong>&gt; 14 days</strong></div>
        <svg class="snn-learn-sparkline" id="spark-cold" viewBox="0 0 200 36" preserveAspectRatio="none"></svg>
      </div>
    </div>

    <!-- ── MINI STAT ROW ── -->
    <div class="snn-learn-mini-stat-row">
      <div class="snn-learn-mini-stat">
        <div class="snn-learn-mini-stat-val">7</div>
        <div class="snn-learn-mini-stat-lbl">Active Courses</div>
      </div>
      <div class="snn-learn-mini-stat">
        <div class="snn-learn-mini-stat-val">4.1 days</div>
        <div class="snn-learn-mini-stat-lbl">Avg. Time to Complete</div>
      </div>
      <div class="snn-learn-mini-stat">
        <div class="snn-learn-mini-stat-val">18.3%</div>
        <div class="snn-learn-mini-stat-lbl">Drop-off Rate</div>
      </div>
      <div class="snn-learn-mini-stat">
        <div class="snn-learn-mini-stat-val">Mar 2</div>
        <div class="snn-learn-mini-stat-lbl">Peak Enrollment Day</div>
      </div>
      <div class="snn-learn-mini-stat">
        <div class="snn-learn-mini-stat-val">2.3h</div>
        <div class="snn-learn-mini-stat-lbl">Avg. Time Between Sessions</div>
      </div>
      <div class="snn-learn-mini-stat">
        <div class="snn-learn-mini-stat-val">41</div>
        <div class="snn-learn-mini-stat-lbl">Completions This Month</div>
      </div>
    </div>

    <!-- ── CHART + FUNNEL ── -->
    <div class="snn-learn-two-col">
      <div class="snn-learn-card">
        <div class="snn-learn-card-header">
          <div class="snn-learn-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            Enrollment Trend
          </div>
          <div class="snn-learn-card-actions">
            <div class="snn-learn-tab-bar">
              <button class="snn-learn-tab-btn active">Enrollments</button>
              <button class="snn-learn-tab-btn">Completions</button>
            </div>
          </div>
        </div>
        <div class="snn-learn-card-body">
          <div class="snn-learn-chart-area">
            <canvas class="snn-learn-chart-canvas" id="trendChart"></canvas>
          </div>
        </div>
      </div>

      <div class="snn-learn-card">
        <div class="snn-learn-card-header">
          <div class="snn-learn-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
            Enrollment Funnel
          </div>
          <div class="snn-learn-card-meta">All time</div>
        </div>
        <div class="snn-learn-card-body">
          <div class="snn-learn-funnel">
            <div class="snn-learn-funnel-row">
              <div class="snn-learn-funnel-label">Enrolled</div>
              <div class="snn-learn-funnel-bar-wrap"><div class="snn-learn-funnel-bar" style="background:#2563EB;width:100%">1,284</div></div>
              <div class="snn-learn-funnel-count">100%</div>
            </div>
            <div class="snn-learn-funnel-row">
              <div class="snn-learn-funnel-label">Started</div>
              <div class="snn-learn-funnel-bar-wrap"><div class="snn-learn-funnel-bar" style="background:#0EA5E9;width:87%">1,117</div></div>
              <div class="snn-learn-funnel-count">87%</div>
            </div>
            <div class="snn-learn-funnel-row">
              <div class="snn-learn-funnel-label">Active</div>
              <div class="snn-learn-funnel-bar-wrap"><div class="snn-learn-funnel-bar" style="background:#059669;width:63%">811</div></div>
              <div class="snn-learn-funnel-count">63%</div>
            </div>
            <div class="snn-learn-funnel-row">
              <div class="snn-learn-funnel-label">Completed</div>
              <div class="snn-learn-funnel-bar-wrap"><div class="snn-learn-funnel-bar" style="background:#16A34A;width:52%">668</div></div>
              <div class="snn-learn-funnel-count">52%</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── COURSE TABLE + AT-RISK ── -->
    <div class="snn-learn-two-col">
      <div class="snn-learn-card">
        <div class="snn-learn-card-header">
          <div class="snn-learn-card-title">Course Performance</div>
        </div>
        <div class="snn-learn-card-body-flush">
          <table>
            <thead>
              <tr>
                <th>Course</th>
                <th>Enrolled</th>
                <th>Completion</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="courseBody">
              <?php foreach ($courses_data as $course) : 
                $course_name = get_the_title($course->course_id) ?: 'Unknown Course';
                $pct = $course->enrolled > 0 ? round(($course->completed / $course->enrolled) * 100) : 0;
                $cls = $pct >= 65 ? '' : ($pct >= 40 ? ' mid' : ' low');
                $status = $pct >= 65 ? '<span class="snn-learn-chip green">Healthy</span>' : ($pct >= 40 ? '<span class="snn-learn-chip amber">Moderate</span>' : '<span class="snn-learn-chip red">Low</span>');
              ?>
              <tr>
                <td><span class="snn-learn-course-dot" style="background:#2563EB"></span><?php echo esc_html($course_name); ?></td>
                <td><?php echo number_format($course->enrolled); ?></td>
                <td>
                  <div class="snn-learn-prog-wrap">
                    <div class="snn-learn-prog-bar"><div class="snn-learn-prog-fill<?php echo $cls; ?>" style="width:<?php echo $pct; ?>%"></div></div>
                    <div class="snn-learn-prog-pct"><?php echo $pct; ?>%</div>
                  </div>
                </td>
                <td><?php echo $status; ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="snn-learn-card">
        <div class="snn-learn-card-header">
          <div class="snn-learn-card-title">At-Risk Students</div>
          <div class="snn-learn-card-meta">Cold &gt; 14 days</div>
        </div>
        <div class="snn-learn-risk-list" id="riskList">
          <?php foreach ($at_risk as $risk) : 
            $user = get_userdata($risk->user_id);
            $name = $user ? $user->display_name : 'Unknown';
            $initials = strtoupper(substr($name, 0, 1));
            $days = floor((time() - $risk->last_activity_at) / (24 * 60 * 60));
          ?>
          <div class="snn-learn-risk-item">
            <div class="snn-learn-avatar"><?php echo $initials; ?></div>
            <div>
              <div style="font-size:13.5px;font-weight:500;color:var(--text)"><?php echo esc_html($name); ?></div>
              <div class="snn-learn-risk-course"><?php echo get_the_title($risk->post_id); ?></div>
            </div>
            <div class="snn-learn-risk-days"><?php echo $days; ?>d inactive</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

  <script>
    // Sparkline and Chart logic here
    function drawSparkline(id, data, color) {
      const svg = document.getElementById(id);
      if (!svg) return;
      const W = 200, H = 36, pad = 2;
      const max = Math.max(...data);
      const min = Math.min(...data);
      const range = max - min || 1;
      const pts = data.map((v, i) => {
        const x = pad + (i / (data.length - 1)) * (W - pad * 2);
        const y = H - pad - ((v - min) / range) * (H - pad * 2);
        return `${x},${y}`;
      });
      const area = `M${pts[0]} ` + pts.slice(1).map(p=>`L${p}`).join(' ') + ` L${W-pad},${H-pad} L${pad},${H-pad} Z`;
      const line = `M${pts[0]} ` + pts.slice(1).map(p=>`L${p}`).join(' ');
      svg.innerHTML = `
        <defs>
          <linearGradient id="grad-${id}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="${color}" stop-opacity="0.15"/>
            <stop offset="100%" stop-color="${color}" stop-opacity="0"/>
          </linearGradient>
        </defs>
        <path d="${area}" fill="url(#grad-${id})"/>
        <path d="${line}" fill="none" stroke="${color}" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
      `;
    }
    drawSparkline('spark-total', [1190,1200,1215,1221,1234,1240,1248,1255,1262,1270,1278,1284], '#2563EB');
    drawSparkline('spark-completion', [58,59,60,61,61,62,61,62,63,63,63,63], '#059669');
    drawSparkline('spark-active', [260,245,250,242,238,248,251,244,239,246,250,248], '#D97706');
    drawSparkline('spark-cold', [72,75,74,77,78,79,80,82,84,85,88,89], '#DC2626');
  </script>
</body>
</html>
