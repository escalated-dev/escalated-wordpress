<?php
/**
 * Admin template: Reports
 *
 * @var array    $by_status                   Tickets grouped by status (status => count).
 * @var array    $by_priority                 Tickets grouped by priority (priority => count).
 * @var array    $by_department_rows          Tickets grouped by department (objects with department_name, count).
 * @var array    $by_agent_rows               Tickets grouped by agent (objects with assigned_to, count).
 * @var int      $total_tickets               Total ticket count.
 * @var int      $open_tickets                Open tickets count.
 * @var int      $resolved_tickets            Resolved tickets count.
 * @var int      $closed_tickets              Closed tickets count.
 * @var int      $sla_first_response_breached SLA first response breach count.
 * @var int      $sla_resolution_breached     SLA resolution breach count.
 * @var int      $tickets_with_sla            Tickets with SLA policy count.
 * @var float    $sla_compliance_rate         SLA compliance percentage.
 * @var float|null $avg_first_response        Average first response time in hours.
 * @var float|null $avg_resolution            Average resolution time in hours.
 * @var int      $recent_tickets              Tickets created in last 30 days.
 * @var array    $statuses                    Ticket statuses from Enums.
 * @var array    $priorities                  Ticket priorities from Enums.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Reports', 'escalated' ); ?></h1>

    <!-- Stats Cards -->
    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 25px;">
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; min-width: 180px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: #1d2327;"><?php echo esc_html( number_format_i18n( $total_tickets ) ); ?></div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'Total Tickets', 'escalated' ); ?></div>
        </div>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #3B82F6; border-radius: 4px; padding: 20px; min-width: 180px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: #3B82F6;"><?php echo esc_html( number_format_i18n( $open_tickets ) ); ?></div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'Open Tickets', 'escalated' ); ?></div>
        </div>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #10B981; border-radius: 4px; padding: 20px; min-width: 180px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: #10B981;"><?php echo esc_html( number_format_i18n( $resolved_tickets ) ); ?></div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'Resolved', 'escalated' ); ?></div>
        </div>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #6B7280; border-radius: 4px; padding: 20px; min-width: 180px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: #6B7280;"><?php echo esc_html( number_format_i18n( $closed_tickets ) ); ?></div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'Closed', 'escalated' ); ?></div>
        </div>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #8B5CF6; border-radius: 4px; padding: 20px; min-width: 180px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: #8B5CF6;"><?php echo esc_html( number_format_i18n( $recent_tickets ) ); ?></div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'Last 30 Days', 'escalated' ); ?></div>
        </div>
    </div>

    <!-- SLA Stats Cards -->
    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 25px;">
        <div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid <?php echo $sla_compliance_rate >= 90 ? '#10B981' : ( $sla_compliance_rate >= 70 ? '#F59E0B' : '#EF4444' ); ?>; border-radius: 4px; padding: 20px; min-width: 200px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: <?php echo $sla_compliance_rate >= 90 ? '#10B981' : ( $sla_compliance_rate >= 70 ? '#F59E0B' : '#EF4444' ); ?>;">
                <?php echo esc_html( $sla_compliance_rate ); ?>%
            </div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'SLA Compliance Rate', 'escalated' ); ?></div>
        </div>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; min-width: 200px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: #EF4444;"><?php echo esc_html( number_format_i18n( $sla_first_response_breached ) ); ?></div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'First Response Breaches', 'escalated' ); ?></div>
        </div>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; min-width: 200px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: #EF4444;"><?php echo esc_html( number_format_i18n( $sla_resolution_breached ) ); ?></div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'Resolution Breaches', 'escalated' ); ?></div>
        </div>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; min-width: 200px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: #1d2327;">
                <?php echo $avg_first_response !== null ? esc_html( $avg_first_response . 'h' ) : '&mdash;'; ?>
            </div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'Avg. First Response', 'escalated' ); ?></div>
        </div>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; min-width: 200px; flex: 1;">
            <div style="font-size: 32px; font-weight: 700; color: #1d2327;">
                <?php echo $avg_resolution !== null ? esc_html( $avg_resolution . 'h' ) : '&mdash;'; ?>
            </div>
            <div style="font-size: 13px; color: #666; margin-top: 4px;"><?php esc_html_e( 'Avg. Resolution Time', 'escalated' ); ?></div>
        </div>
    </div>

    <div style="display: flex; gap: 20px; flex-wrap: wrap;">

        <!-- Tickets by Status -->
        <div style="flex: 1; min-width: 300px;">
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px;">
                <h3 style="margin-top: 0; font-size: 14px;"><?php esc_html_e( 'Tickets by Status', 'escalated' ); ?></h3>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Status', 'escalated' ); ?></th>
                            <th style="width: 80px; text-align: right;"><?php esc_html_e( 'Count', 'escalated' ); ?></th>
                            <th style="width: 120px;"><?php esc_html_e( 'Percentage', 'escalated' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $statuses as $key => $data ) :
                            $count = $by_status[ $key ] ?? 0;
                            $pct   = $total_tickets > 0 ? round( ( $count / $total_tickets ) * 100, 1 ) : 0;
                        ?>
                            <tr>
                                <td>
                                    <span style="display: inline-block; width: 10px; height: 10px; background: <?php echo esc_attr( $data['color'] ); ?>; border-radius: 2px; margin-right: 5px; vertical-align: middle;"></span>
                                    <?php echo esc_html( $data['label'] ); ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
                                <td>
                                    <div style="background: #f0f0f0; border-radius: 3px; height: 18px; position: relative;">
                                        <div style="background: <?php echo esc_attr( $data['color'] ); ?>; height: 100%; border-radius: 3px; width: <?php echo esc_attr( $pct ); ?>%; min-width: <?php echo $pct > 0 ? '2px' : '0'; ?>;"></div>
                                        <span style="position: absolute; right: 5px; top: 0; line-height: 18px; font-size: 11px; color: #555;"><?php echo esc_html( $pct ); ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tickets by Priority -->
        <div style="flex: 1; min-width: 300px;">
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px;">
                <h3 style="margin-top: 0; font-size: 14px;"><?php esc_html_e( 'Tickets by Priority', 'escalated' ); ?></h3>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Priority', 'escalated' ); ?></th>
                            <th style="width: 80px; text-align: right;"><?php esc_html_e( 'Count', 'escalated' ); ?></th>
                            <th style="width: 120px;"><?php esc_html_e( 'Percentage', 'escalated' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $priorities as $key => $data ) :
                            $count = $by_priority[ $key ] ?? 0;
                            $pct   = $total_tickets > 0 ? round( ( $count / $total_tickets ) * 100, 1 ) : 0;
                        ?>
                            <tr>
                                <td>
                                    <span style="display: inline-block; width: 10px; height: 10px; background: <?php echo esc_attr( $data['color'] ); ?>; border-radius: 2px; margin-right: 5px; vertical-align: middle;"></span>
                                    <?php echo esc_html( $data['label'] ); ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
                                <td>
                                    <div style="background: #f0f0f0; border-radius: 3px; height: 18px; position: relative;">
                                        <div style="background: <?php echo esc_attr( $data['color'] ); ?>; height: 100%; border-radius: 3px; width: <?php echo esc_attr( $pct ); ?>%; min-width: <?php echo $pct > 0 ? '2px' : '0'; ?>;"></div>
                                        <span style="position: absolute; right: 5px; top: 0; line-height: 18px; font-size: 11px; color: #555;"><?php echo esc_html( $pct ); ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">

        <!-- Tickets by Department -->
        <div style="flex: 1; min-width: 300px;">
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px;">
                <h3 style="margin-top: 0; font-size: 14px;"><?php esc_html_e( 'Tickets by Department', 'escalated' ); ?></h3>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Department', 'escalated' ); ?></th>
                            <th style="width: 80px; text-align: right;"><?php esc_html_e( 'Count', 'escalated' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $by_department_rows ) ) : ?>
                            <tr>
                                <td colspan="2"><?php esc_html_e( 'No data available.', 'escalated' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $by_department_rows as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row->department_name ?: __( 'Unassigned', 'escalated' ) ); ?></td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo esc_html( number_format_i18n( $row->count ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tickets by Agent -->
        <div style="flex: 1; min-width: 300px;">
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px;">
                <h3 style="margin-top: 0; font-size: 14px;"><?php esc_html_e( 'Top Agents by Ticket Count', 'escalated' ); ?></h3>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Agent', 'escalated' ); ?></th>
                            <th style="width: 80px; text-align: right;"><?php esc_html_e( 'Tickets', 'escalated' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $by_agent_rows ) ) : ?>
                            <tr>
                                <td colspan="2"><?php esc_html_e( 'No data available.', 'escalated' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $by_agent_rows as $row ) :
                                $agent = get_userdata( (int) $row->assigned_to );
                                $agent_name = $agent ? $agent->display_name : __( 'Unknown', 'escalated' );
                            ?>
                                <tr>
                                    <td>
                                        <?php echo get_avatar( (int) $row->assigned_to, 24, '', '', [ 'style' => 'vertical-align: middle; margin-right: 5px; border-radius: 50%;' ] ); ?>
                                        <?php echo esc_html( $agent_name ); ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo esc_html( number_format_i18n( $row->count ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
