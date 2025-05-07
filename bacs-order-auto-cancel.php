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
    private $test_mode = true; // 测试模式开关
    private $test_cancel_minutes = 4; // 测试模式下的取消时间（分钟）
    private $test_reminder_minutes = array(2, 3); // 测试模式下的提醒时间（分钟）

    public function __construct() {
        // 注册激活钩子
        register_activation_hook(__FILE__, array($this, 'activate'));
        // 注册停用钩子
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        // 添加定时任务钩子
        add_action('bacs_order_check_event', array($this, 'check_orders'));
        // 添加邮件模板
        add_filter('woocommerce_email_styles', array($this, 'add_email_styles'));

        // 如果是测试模式，添加每分钟的定时任务
        if ($this->test_mode) {
            add_filter('cron_schedules', array($this, 'add_test_schedule'));
        }
    }

    /**
     * 添加测试用的定时任务间隔
     */
    public function add_test_schedule($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __('每分钟')
        );
        return $schedules;
    }

    /**
     * 插件激活时设置定时任务
     */
    public function activate() {
        if (!wp_next_scheduled('bacs_order_check_event')) {
            if ($this->test_mode) {
                wp_schedule_event(time(), 'every_minute', 'bacs_order_check_event');
            } else {
                wp_schedule_event(time(), 'hourly', 'bacs_order_check_event');
            }
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
            
            if ($this->test_mode) {
                $minutes_passed = (time() - $order_date->getTimestamp()) / 60;
                $this->process_test_order($order, $minutes_passed);
            } else {
                $hours_passed = (time() - $order_date->getTimestamp()) / 3600;
                $this->process_live_order($order, $hours_passed);
            }
        }
    }

    /**
     * 处理测试模式下的订单
     */
    private function process_test_order($order, $minutes_passed) {
        // 检查是否需要发送提醒邮件
        foreach ($this->test_reminder_minutes as $reminder_minute) {
            if ($minutes_passed >= $reminder_minute && $minutes_passed < $reminder_minute + 1) {
                $this->send_reminder_email($order, true);
                break;
            }
        }

        // 检查是否需要取消订单
        if ($minutes_passed >= $this->test_cancel_minutes) {
            $this->cancel_order($order, true);
        }
    }

    /**
     * 处理正式模式下的订单
     */
    private function process_live_order($order, $hours_passed) {
        $remaining = $this->cancel_hours - floor($hours_passed);
        foreach ($this->reminder_hours as $reminder_hour) {
            if ($hours_passed >= $reminder_hour && $hours_passed < $reminder_hour + 1) {
                $this->send_reminder_email($order, false, $remaining);
                break;
            }
        }
        if ($hours_passed >= $this->cancel_hours) {
            $this->cancel_order($order, false);
        }
    }

    /**
     * 发送提醒邮件
     */
    private function send_reminder_email($order, $is_test = false, $remaining_hours = null) {
        $order_id = $order->get_id();
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $order_total = $order->get_formatted_order_total();
        $bank_details = $this->get_bank_details_es();
        $order_url = $order->get_checkout_order_received_url();
        $order_link_html = '<a href="' . esc_url($order_url) . '" style="display:inline-block;padding:10px 20px;background:#0071a1;color:#fff;text-decoration:none;border-radius:4px;margin:10px 0;">Ver mi pedido</a>';
        if (empty(trim(strip_tags($bank_details)))) {
            $bank_details = 'Por favor, consulte los datos bancarios en nuestra web o contacte con soporte.';
        }

        // 判断剩余时间
        $order_date = $order->get_date_created();
        if ($is_test) {
            $minutes_passed = (time() - $order_date->getTimestamp()) / 60;
            $remaining = ($minutes_passed < 3) ? 2 : 1;
            $subject = sprintf(__('[PRUEBA] ⏰ URGENTE: Su pedido será cancelado en %d minuto(s) - Pedido #%s', 'bacs-order-auto-cancel'), $remaining, $order_id);
            $time_text = "Su pedido será cancelado automáticamente en {$remaining} minuto(s) si no recibimos el pago.";
        } elseif ($remaining_hours !== null) {
            $subject = sprintf(__('⏰ URGENTE: Su pedido será cancelado en %d horas - Pedido #%s', 'bacs-order-auto-cancel'), $remaining_hours, $order_id);
            $time_text = "Su pedido será cancelado automáticamente en {$remaining_hours} horas si no recibimos el pago.";
        } else {
            $subject = sprintf(__('Recordatorio de pago - Pedido #%s', 'bacs-order-auto-cancel'), $order_id);
            $time_text = "Por favor, realice el pago lo antes posible para evitar la cancelación automática del pedido.";
        }

        $message = sprintf(
            __('Estimado/a %s,<br><br>
            Le informamos que su pedido #%s aún no ha sido pagado.<br>
            Importe del pedido: %s<br><br>
            %s<br><br>
            Información de la cuenta bancaria:<br>%s<br>
            %s<br>
            Si ya ha realizado el pago, por favor ignore este mensaje.<br>
            Si tiene alguna duda o necesita asistencia, no dude en ponerse en contacto con nosotros.<br><br>
            Atentamente,<br>
            El equipo de atención al cliente de ELE-GATE', 'bacs-order-auto-cancel'),
            $customer_name,
            $order_id,
            $order_total,
            $time_text,
            $bank_details,
            $order_link_html
        );

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($customer_email, $subject, $message, $headers);

        if ($is_test) {
            error_log(sprintf('Prueba: recordatorio enviado - Pedido #%s', $order_id));
        }
    }

    /**
     * 取消订单
     */
    private function cancel_order($order, $is_test = false) {
        if ($is_test) {
            $order->update_status('cancelled', __('[PRUEBA] Pedido cancelado automáticamente - más de 4 minutos sin pago', 'bacs-order-auto-cancel'));
            error_log(sprintf('Prueba: pedido cancelado - Pedido #%s', $order->get_id()));
        } else {
            $order->update_status('cancelled', __('Pedido cancelado automáticamente - más de 72 horas sin pago', 'bacs-order-auto-cancel'));
        }
        $customer_email = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $order_id = $order->get_id();
        $order_url = $order->get_checkout_order_received_url();
        $order_link_html = '<a href="' . esc_url($order_url) . '" style="display:inline-block;padding:10px 20px;background:#0071a1;color:#fff;text-decoration:none;border-radius:4px;margin:10px 0;">Ver mi pedido</a>';

        if ($is_test) {
            $subject = sprintf(__('[PRUEBA] Pedido cancelado - Pedido #%s', 'bacs-order-auto-cancel'), $order_id);
            $message = sprintf(
                __('[Modo de prueba]<br><br>Estimado/a %s,<br><br>
                Lamentamos informarle que su pedido #%s ha sido cancelado automáticamente porque no hemos recibido el pago en el plazo establecido.<br>
                %s<br>
                Si ya ha realizado el pago o cree que esto es un error, por favor póngase en contacto con nuestro equipo de atención al cliente.<br>
                Si desea volver a realizar su compra, puede hacerlo en cualquier momento.<br><br>
                Disculpe las molestias y gracias por su comprensión.<br><br>
                Atentamente,<br>
                El equipo de atención al cliente de ELE-GATE', 'bacs-order-auto-cancel'),
                $customer_name,
                $order_id,
                $order_link_html
            );
        } else {
            $subject = sprintf(__('Pedido cancelado - Pedido #%s', 'bacs-order-auto-cancel'), $order_id);
            $message = sprintf(
                __('Estimado/a %s,<br><br>
                Lamentamos informarle que su pedido #%s ha sido cancelado automáticamente porque no hemos recibido el pago en el plazo establecido (72 horas).<br>
                %s<br>
                Si ya ha realizado el pago o cree que esto es un error, por favor póngase en contacto con nuestro equipo de atención al cliente.<br>
                Si desea volver a realizar su compra, puede hacerlo en cualquier momento.<br><br>
                Disculpe las molestias y gracias por su comprensión.<br><br>
                Atentamente,<br>
                El equipo de atención al cliente de ELE-GATE', 'bacs-order-auto-cancel'),
                $customer_name,
                $order_id,
                $order_link_html
            );
        }

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
                    $line .= 'Titular: ' . esc_html($account['account_name']) . '<br>';
                }
                if (!empty($account['account_number'])) {
                    $line .= 'Número de cuenta: ' . esc_html($account['account_number']) . '<br>';
                }
                if (!empty($account['sort_code'])) {
                    $line .= 'Código bancario: ' . esc_html($account['sort_code']) . '<br>';
                }
                if (!empty($account['iban'])) {
                    $line .= 'IBAN: ' . esc_html($account['iban']) . '<br>';
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
