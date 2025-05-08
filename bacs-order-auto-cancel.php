<?php
/**
 * Plugin Name: BACS Order Auto Cancel
 * Plugin URI:  
 * Description: 自动取消超过72小时未支付的BACS订单，并在24小时和48小时发送付款提醒邮件
 * Version: 1.0.0
 * Author: yitu
 * Author URI: 
 * Text Domain: bacs-order-auto-cancel
 */

if (!defined('ABSPATH')) {
    exit;
}

class BACS_Order_Auto_Cancel {
    private $check_interval = 3600; // 每小时检查一次
    private $cancel_hours = 72; // 72小时后取消
    private $reminder_hours = array(24, 48); // 24小时和48小时提醒

    public function __construct() {
        // 注册激活钩子
        register_activation_hook(__FILE__, array($this, 'activate'));
        // 注册停用钩子
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        // 添加定时任务钩子
        add_action('bacs_order_check_event', array($this, 'check_orders'));
        // 添加邮件模板
        add_filter('woocommerce_email_styles', array($this, 'add_email_styles'));
    }

    /**
     * 插件激活时设置定时任务
     */
    public function activate() {
        if (!wp_next_scheduled('bacs_order_check_event')) {
            wp_schedule_event(time(), 'hourly', 'bacs_order_check_event');
        }
    }

    /**
     * 插件停用时清除定时任务
     */
    public function deactivate() {
        wp_clear_scheduled_hook('bacs_order_check_event');
    }

    /**
     * 检查订单状态并执行相应操作
     */
    public function check_orders() {
        $args = array(
            'status' => 'on-hold',
            'payment_method' => 'bacs',
            'limit' => -1,
        );

        $orders = wc_get_orders($args);

        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $order_date = $order->get_date_created();
            $hours_passed = (time() - $order_date->getTimestamp()) / 3600;
            $this->process_live_order($order, $hours_passed);
        }
    }

    /**
     * 处理正式模式下的订单
     */
    private function process_live_order($order, $hours_passed) {
        $remaining = $this->cancel_hours - floor($hours_passed);
        foreach ($this->reminder_hours as $reminder_hour) {
            if ($hours_passed >= $reminder_hour && $hours_passed < $reminder_hour + 1) {
                $this->send_reminder_email($order, $remaining);
                break;
            }
        }
        if ($hours_passed >= $this->cancel_hours) {
            $this->cancel_order($order);
        }
    }

    /**
     * 发送提醒邮件
     */
    private function send_reminder_email($order, $remaining_hours = null) {
        $order_id = $order->get_id();
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $order_total = $order->get_formatted_order_total();
        $bank_details = $this->get_bank_details_es();
        $order_url = $order->get_checkout_order_received_url();
        $order_link_html = '<a href="' . esc_url($order_url) . '" style="display:inline-block;padding:12px 28px;background:#00ABC5;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px;box-shadow:0 2px 8px rgba(0,171,197,0.15);margin:18px 0 10px 0;">Ver mi pedido</a>';
        $logo_url = 'https://img.5005360.xyz/wp-content/uploads/2022/05/APPLOGO.png';
        $site_url = get_site_url();
        if (empty(trim(strip_tags($bank_details)))) {
            $bank_details = 'Por favor, consulte los datos bancarios en nuestra web o contacte con soporte.';
        }

        // 推荐同类产品
        $recommend_html = '';
        $items = $order->get_items();
        $main_product_id = null;
        foreach ($items as $item) {
            $main_product_id = $item->get_product_id();
            break;
        }
        $recommend_products = array();
        if ($main_product_id) {
            $terms = get_the_terms($main_product_id, 'product_cat');
            if ($terms && !is_wp_error($terms)) {
                $cat_id = $terms[0]->term_id;
                $args = array(
                    'post_type' => 'product',
                    'posts_per_page' => 4,
                    'post__not_in' => array($main_product_id),
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'product_cat',
                            'field' => 'term_id',
                            'terms' => $cat_id,
                        ),
                    ),
                    'meta_query' => array(
                        array(
                            'key' => '_stock_status',
                            'value' => 'instock',
                        ),
                    ),
                );
                $query = new WP_Query($args);
                if ($query->have_posts()) {
                    $recommend_html .= '<div style="margin:30px 0 0 0;text-align:center;"><div style="font-weight:bold;color:#00ABC5;font-size:17px;margin-bottom:12px;">Quizá también le interese</div><table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto;border-collapse:collapse;"><tr>';
                    while ($query->have_posts()) {
                        $query->the_post();
                        global $product;
                        $product = wc_get_product(get_the_ID());
                        $img = get_the_post_thumbnail_url(get_the_ID(), 'medium');
                        if (empty($img) || !filter_var($img, FILTER_VALIDATE_URL)) {
                            $img = wc_placeholder_img_src();
                        }
                        $title = get_the_title();
                        $price = $product ? $product->get_price_html() : '';
                        $link = get_permalink();
                        $recommend_html .= '<td align="center" valign="top" style="width:140px;padding:0 8px 0 0;vertical-align:top;">
                            <a href="' . esc_url($link) . '" style="text-decoration:none;display:block;">
                                <img src="' . esc_url($img) . '" alt="' . esc_attr($title) . '" style="width:100px;height:100px;object-fit:contain;border-radius:5px;margin-bottom:7px;display:block;">
                                <div style="font-size:13px;color:#222;font-weight:bold;height:32px;line-height:16px;overflow:hidden;display:block;margin-bottom:2px;">' . esc_html($title) . '</div>
                                <div style="color:#00ABC5;font-size:14px;margin:4px 0 0 0;">' . $price . '</div>
                            </a>
                        </td>';
                    }
                    wp_reset_postdata();
                    $recommend_html .= '</tr></table></div>';
                }
            }
        }

        if ($remaining_hours !== null) {
            $subject = sprintf(__('⏰ URGENTE: Su pedido será cancelado en %d horas - Pedido #%s', 'bacs-order-auto-cancel'), $remaining_hours, $order_id);
            $time_text = "<span style=\"color:#d32f2f;font-weight:bold;\">Su pedido será cancelado automáticamente en {$remaining_hours} horas si no recibimos el pago.</span>";
        } else {
            $subject = sprintf(__('Recordatorio de pago - Pedido #%s', 'bacs-order-auto-cancel'), $order_id);
            $time_text = "Por favor, realice el pago lo antes posible para evitar la cancelación automática del pedido.";
        }

        $message = '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.07);padding:32px 28px 24px 28px;font-family:Arial,sans-serif;line-height:1.7;color:#222;">
            <div style="text-align:center;margin-bottom:18px;"><img src="' . esc_url($logo_url) . '" alt="ELE-GATE" style="max-width:120px;max-height:60px;"></div>
            <h2 style="color:#00ABC5;margin-top:0;margin-bottom:18px;font-size:22px;">Estimado/a ' . esc_html($customer_name) . ',</h2>
            <p style="font-size:16px;margin:0 0 12px 0;">Le informamos que su pedido <strong style="color:#00ABC5;">#' . esc_html($order_id) . '</strong> aún no ha sido pagado.</p>
            <div style="background:#f8f9fa;padding:16px 18px;border-radius:7px;margin:18px 0 18px 0;">
                <p style="margin:0;font-size:16px;">Importe del pedido: <strong style="color:#d32f2f;font-size:18px;">' . $order_total . '</strong></p>
                <p style="margin:0 0 0 0;font-size:15px;">' . $time_text . '</p>
            </div>
            <div style="background:#f1f8fb;padding:14px 18px;border-radius:7px;margin:18px 0 18px 0;">
                <p style="margin:0 0 8px 0;font-weight:bold;color:#00ABC5;">Información de la cuenta bancaria:</p>
                <div style="font-size:15px;color:#333;">' . $bank_details . '</div>
            </div>
            <div style="text-align:center;margin:18px 0 18px 0;">' . $order_link_html . '</div>
            ' . $recommend_html . '
            <p style="font-size:14px;color:#666;margin:18px 0 0 0;">Si ya ha realizado el pago, por favor ignore este mensaje.<br>Si tiene alguna duda o necesita asistencia, no dude en ponerse en contacto con nosotros.</p>
            <div style="margin-top:32px;border-top:1px solid #e0e0e0;padding-top:18px;text-align:right;">
                <span style="color:#00ABC5;font-weight:bold;font-size:15px;">Atentamente,<br>El equipo de atención al cliente de ELE-GATE</span>
            </div>
            <div style="text-align:center;margin-top:24px;font-size:14px;"><a href="' . esc_url($site_url) . '" style="color:#00ABC5;text-decoration:underline;font-weight:bold;">Visite nuestro sitio web</a></div>
        </div>';

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($customer_email, $subject, $message, $headers);
    }

    /**
     * 取消订单
     */
    private function cancel_order($order) {
        $order->update_status('cancelled', __('Pedido cancelado automáticamente - más de 72 horas sin pago', 'bacs-order-auto-cancel'));
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $order_id = $order->get_id();
        $order_url = $order->get_checkout_order_received_url();
        $order_link_html = '<a href="' . esc_url($order_url) . '" style="display:inline-block;padding:12px 28px;background:#00ABC5;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px;box-shadow:0 2px 8px rgba(0,171,197,0.15);margin:18px 0 10px 0;">Ver mi pedido</a>';

        $subject = sprintf(__('Pedido cancelado - Pedido #%s', 'bacs-order-auto-cancel'), $order_id);
        $message = '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.07);padding:32px 28px 24px 28px;font-family:Arial,sans-serif;line-height:1.7;color:#222;">
            <h2 style="color:#d32f2f;margin-top:0;margin-bottom:18px;font-size:22px;">Estimado/a ' . esc_html($customer_name) . ',</h2>
            <div style="background:#fbe9e7;padding:16px 18px;border-radius:7px;margin:18px 0 18px 0;">
                <p style="margin:0 0 8px 0;font-size:16px;color:#d32f2f;font-weight:bold;">Lamentamos informarle que su pedido <strong>#' . esc_html($order_id) . '</strong> ha sido cancelado automáticamente porque no hemos recibido el pago en el plazo establecido (72 horas).</p>
            </div>
            <div style="text-align:center;margin:18px 0 18px 0;">' . $order_link_html . '</div>
            <p style="font-size:14px;color:#666;margin:18px 0 0 0;">Si ya ha realizado el pago o cree que esto es un error, por favor póngase en contacto con nuestro equipo de atención al cliente.<br>Si desea volver a realizar su compra, puede hacerlo en cualquier momento.</p>
            <div style="margin-top:32px;border-top:1px solid #e0e0e0;padding-top:18px;text-align:right;">
                <span style="color:#00ABC5;font-weight:bold;font-size:15px;">Atentamente,<br>El equipo de atención al cliente de ELE-GATE</span>
            </div>
        </div>';

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($customer_email, $subject, $message, $headers);
    }

    /**
     * 获取银行账户信息（西班牙语，仅显示有内容的字段）
     */
    private function get_bank_details_es() {
        $accounts = get_option('woocommerce_bacs_accounts');
        $bank_details = '';
        if (!empty($accounts) && is_array($accounts)) {
            foreach ($accounts as $account) {
                $line = '';
                if (!empty($account['bank_name'])) {
                    $line .= 'Banco: ' . esc_html($account['bank_name']) . '<br>';
                }
                if (!empty($account['account_name'])) {
                    $line .= 'Nombre del titular: ' . esc_html($account['account_name']) . '<br>';
                }
                if (!empty($account['account_number'])) {
                    $line .= 'Número de cuenta: ' . esc_html($account['account_number']) . '<br>';
                }
                if (!empty($account['sort_code'])) {
                    $line .= 'Código bancario: ' . esc_html($account['sort_code']) . '<br>';
                }
                if (!empty($account['iban'])) {
                    $line .= 'Clave: ' . esc_html($account['iban']) . '<br>';
                }
                if (!empty($account['bic'])) {
                    $line .= 'BIC: ' . esc_html($account['bic']) . '<br>';
                }
                if (!empty($line)) {
                    $bank_details .= $line . '<br>';
                }
            }
        }
        return $bank_details;
    }

    /**
     * 添加邮件样式
     */
    public function add_email_styles($css) {
        $css .= '
            .bacs-reminder-email {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #00ABC5;
            }
            .bacs-reminder-email h2 {
                color: #00ABC5;
            }
            .bacs-reminder-email .bank-details {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin: 15px 0;
            }
        ';
        return $css;
    }
}

// 初始化插件
new BACS_Order_Auto_Cancel();
