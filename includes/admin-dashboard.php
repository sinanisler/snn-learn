<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function snn_learn_dashboard_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'snn_learn_enrollments';
    $now   = time();

    // KPI Metrics
    $total_enrollments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

    $thirty_days_ago    = $now - ( 30 * 86400 );
    $recent_enrollments = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE enrolled_at >= %d", $thirty_days_ago
    ) );

    $total_for_rate = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    $completed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE completed_at IS NOT NULL" );
    $completion_rate = $total_for_rate > 0 ? round( ( $completed_count / $total_for_rate ) * 100, 1 ) : 0;

    $seven_days_ago     = $now - ( 7 * 86400 );
    $weekly_active      = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE last_activity_at >= %d", $seven_days_ago
    ) );

    $fourteen_days_ago = $now - ( 14 * 86400 );
    $at_risk_count     = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE last_activity_at < %d AND completed_at IS NULL", $fourteen_days_ago
    ) );

    // Mini Stats
    $active_courses = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT course_id) FROM $table" );

    $avg_completion_days = $wpdb->get_var(
        "SELECT AVG(completed_at - enrolled_at) / 86400 FROM $table WHERE completed_at IS NOT NULL"
    );
    $avg_completion_days = $avg_completion_days ? round( (float) $avg_completion_days, 1 ) : '—';

    $peak_day_row = $wpdb->get_row(
        "SELECT FROM_UNIXTIME(enrolled_at, '%Y-%m-%d') AS day, COUNT(*) AS cnt FROM $table GROUP BY day ORDER BY cnt DESC LIMIT 1"
    );
    $peak_day = $peak_day_row ? $peak_day_row->day . ' (' . $peak_day_row->cnt . ')' : '—';

    // Enrollment Trend (last 30 days)
    $trend_data = $wpdb->get_results( $wpdb->prepare(
        "SELECT FROM_UNIXTIME(enrolled_at, '%%Y-%%m-%%d') AS day, COUNT(*) AS cnt
         FROM $table WHERE enrolled_at >= %d GROUP BY day ORDER BY day",
        $thirty_days_ago
    ) );
    $trend_labels = array();
    $trend_values = array();
    foreach ( $trend_data as $row ) {
        $trend_labels[] = $row->day;
        $trend_values[] = (int) $row->cnt;
    }

    // Course Performance
    $course_performance = $wpdb->get_results(
        "SELECT course_id, COUNT(*) AS enrolled, SUM(completed_at IS NOT NULL) AS completed
         FROM $table GROUP BY course_id ORDER BY enrolled DESC LIMIT 20"
    );

    // At-Risk Students
    $at_risk_students = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, course_id, last_activity_at FROM $table
         WHERE last_activity_at < %d AND completed_at IS NULL
         ORDER BY last_activity_at ASC LIMIT 20",
        $fourteen_days_ago
    ) );

    // Recent Activity Feed
    $recent_activity = $wpdb->get_results(
        "SELECT user_id, course_id, post_id, enrolled_at, completed_at
         FROM $table ORDER BY GREATEST(COALESCE(completed_at,0), enrolled_at) DESC LIMIT 20"
    );
    ?>
    <div class="wrap snn-learn-dashboard-wrap">
        <h1 class="snn-learn-dashboard-title">SNN Learn Dashboard</h1>

        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">

        <!-- KPI Cards -->
        <div class="snn-learn-kpi-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin:24px 0;">

            <div class="snn-learn-kpi-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
                <div style="font-size:13px;color:#64748b;margin-bottom:4px;">Total Enrollments</div>
                <div style="font-size:32px;font-weight:700;color:#1e293b;"><?php echo number_format( $total_enrollments ); ?></div>
            </div>

            <div class="snn-learn-kpi-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
                <div style="font-size:13px;color:#64748b;margin-bottom:4px;">Recent (30 Days)</div>
                <div style="font-size:32px;font-weight:700;color:#6366f1;"><?php echo number_format( $recent_enrollments ); ?></div>
            </div>

            <div class="snn-learn-kpi-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
                <div style="font-size:13px;color:#64748b;margin-bottom:4px;">Completion Rate</div>
                <div style="font-size:32px;font-weight:700;color:#10b981;"><?php echo $completion_rate; ?>%</div>
            </div>

            <div class="snn-learn-kpi-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
                <div style="font-size:13px;color:#64748b;margin-bottom:4px;">Weekly Active</div>
                <div style="font-size:32px;font-weight:700;color:#3b82f6;"><?php echo number_format( $weekly_active ); ?></div>
            </div>

            <div class="snn-learn-kpi-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
                <div style="font-size:13px;color:#64748b;margin-bottom:4px;">At-Risk (Gone Cold)</div>
                <div style="font-size:32px;font-weight:700;color:#ef4444;"><?php echo number_format( $at_risk_count ); ?></div>
            </div>

        </div>

        <!-- Mini Stats -->
        <div class="snn-learn-mini-stats" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:24px;">

            <div class="snn-learn-mini-stat" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
                <div style="font-size:12px;color:#94a3b8;">Active Courses</div>
                <div style="font-size:22px;font-weight:600;color:#334155;"><?php echo $active_courses; ?></div>
            </div>

            <div class="snn-learn-mini-stat" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
                <div style="font-size:12px;color:#94a3b8;">Avg. Completion (Days)</div>
                <div style="font-size:22px;font-weight:600;color:#334155;"><?php echo esc_html( $avg_completion_days ); ?></div>
            </div>

            <div class="snn-learn-mini-stat" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
                <div style="font-size:12px;color:#94a3b8;">Peak Enrollment Day</div>
                <div style="font-size:22px;font-weight:600;color:#334155;"><?php echo esc_html( $peak_day ); ?></div>
            </div>

        </div>

        <!-- Enrollment Trend Chart -->
        <div class="snn-learn-chart-section" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:24px;">
            <h2 style="font-size:18px;font-weight:600;margin:0 0 16px;">Enrollment Trend (Last 30 Days)</h2>
            <canvas id="snn-learn-trend-chart" height="80" class="snn-learn-chart-canvas"></canvas>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            var ctx = document.getElementById('snn-learn-trend-chart');
            if(ctx){
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo wp_json_encode( $trend_labels ); ?>,
                        datasets: [{
                            label: 'Enrollments',
                            data: <?php echo wp_json_encode( $trend_values ); ?>,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99,102,241,0.1)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3,
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        }
                    }
                });
            }
        });
        </script>

        <!-- Course Performance Table -->
        <div class="snn-learn-table-section" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:24px;">
            <h2 style="font-size:18px;font-weight:600;margin:0 0 16px;">Course Performance</h2>
            <table class="snn-learn-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid #e2e8f0;text-align:left;">
                        <th style="padding:10px 12px;font-size:13px;color:#64748b;">Course</th>
                        <th style="padding:10px 12px;font-size:13px;color:#64748b;">Enrolled</th>
                        <th style="padding:10px 12px;font-size:13px;color:#64748b;">Completed</th>
                        <th style="padding:10px 12px;font-size:13px;color:#64748b;">Rate</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $course_performance ) : ?>
                    <?php foreach ( $course_performance as $cp ) :
                        $course_title = get_the_title( $cp->course_id );
                        $rate = $cp->enrolled > 0 ? round( ( $cp->completed / $cp->enrolled ) * 100, 1 ) : 0;
                    ?>
                    <tr class="snn-learn-table-row" style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 12px;font-size:14px;">
                            <?php echo $course_title ? esc_html( $course_title ) : '#' . $cp->course_id; ?>
                        </td>
                        <td style="padding:10px 12px;font-size:14px;"><?php echo (int) $cp->enrolled; ?></td>
                        <td style="padding:10px 12px;font-size:14px;"><?php echo (int) $cp->completed; ?></td>
                        <td style="padding:10px 12px;font-size:14px;">
                            <span style="background:<?php echo $rate >= 50 ? '#dcfce7' : '#fef3c7'; ?>;color:<?php echo $rate >= 50 ? '#166534' : '#92400e'; ?>;padding:2px 8px;border-radius:9999px;font-size:12px;font-weight:600;">
                                <?php echo $rate; ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="4" style="padding:20px;text-align:center;color:#94a3b8;">No data yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Two column layout: At-Risk + Recent Activity -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">

            <!-- At-Risk Students -->
            <div class="snn-learn-atrisk-section" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">
                <h2 style="font-size:18px;font-weight:600;margin:0 0 16px;color:#ef4444;">At-Risk Students</h2>
                <table class="snn-learn-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid #e2e8f0;text-align:left;">
                            <th style="padding:8px 10px;font-size:12px;color:#64748b;">User</th>
                            <th style="padding:8px 10px;font-size:12px;color:#64748b;">Course</th>
                            <th style="padding:8px 10px;font-size:12px;color:#64748b;">Last Activity</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( $at_risk_students ) : ?>
                        <?php foreach ( $at_risk_students as $ars ) :
                            $user = get_userdata( $ars->user_id );
                            $uname = $user ? $user->display_name : '#' . $ars->user_id;
                            $ctitle = get_the_title( $ars->course_id );
                            $last = $ars->last_activity_at ? date( 'M j, Y', $ars->last_activity_at ) : '—';
                        ?>
                        <tr class="snn-learn-table-row" style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 10px;font-size:13px;"><?php echo esc_html( $uname ); ?></td>
                            <td style="padding:8px 10px;font-size:13px;"><?php echo $ctitle ? esc_html( $ctitle ) : '#' . $ars->course_id; ?></td>
                            <td style="padding:8px 10px;font-size:13px;color:#ef4444;"><?php echo esc_html( $last ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="3" style="padding:16px;text-align:center;color:#94a3b8;">No at-risk students.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Activity Feed -->
            <div class="snn-learn-activity-section" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">
                <h2 style="font-size:18px;font-weight:600;margin:0 0 16px;">Recent Activity</h2>
                <div class="snn-learn-activity-list" style="max-height:400px;overflow-y:auto;">
                <?php if ( $recent_activity ) : ?>
                    <?php foreach ( $recent_activity as $ra ) :
                        $user = get_userdata( $ra->user_id );
                        $uname = $user ? $user->display_name : '#' . $ra->user_id;
                        $ctitle = get_the_title( $ra->course_id );
                        $ptitle = get_the_title( $ra->post_id );
                        $is_completed = ! empty( $ra->completed_at );
                        $time_str = date( 'M j, H:i', $is_completed ? $ra->completed_at : $ra->enrolled_at );
                    ?>
                    <div class="snn-learn-activity-item" style="padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px;">
                        <strong><?php echo esc_html( $uname ); ?></strong>
                        <?php if ( $is_completed ) : ?>
                            <span style="color:#10b981;">completed</span>
                        <?php else : ?>
                            <span style="color:#6366f1;">enrolled in</span>
                        <?php endif; ?>
                        <em><?php echo $ptitle ? esc_html( $ptitle ) : '#' . $ra->post_id; ?></em>
                        <span style="color:#94a3b8;margin-left:6px;"><?php echo esc_html( $time_str ); ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div style="padding:16px;text-align:center;color:#94a3b8;">No activity yet.</div>
                <?php endif; ?>
                </div>
            </div>

        </div>

    </div>
    <?php
}
