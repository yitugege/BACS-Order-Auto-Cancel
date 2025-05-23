# BACS Order Auto Cancel

## 插件简介

**BACS Order Auto Cancel** 是一款专为 WooCommerce 商城开发的自动化插件，支持以下功能：

- 自动取消超过 72 小时未支付的 BACS（银行转账）订单
- 在订单创建后 24 小时和 48 小时自动发送付款提醒邮件
- 邮件内容支持西班牙语，内容礼貌且带有紧急感
- 邮件中自动插入银行账户信息和"Ver mi pedido"按钮，跳转到 thank you 页面
 

---

## 功能特性

- **自动取消**：订单创建 72 小时后仍未支付，自动取消并通知客户
- **分阶段提醒**：
  - 24 小时后提醒：告知客户 48 小时内未支付将取消
  - 48 小时后提醒：告知客户 24 小时内未支付将取消
- **紧急感邮件标题**：标题自动显示剩余小时数，提升客户关注度
- **银行信息智能展示**：只显示有内容的字段，无空白行
 

---

## 安装方法

1. 上传插件文件夹到 `wp-content/plugins/bacs-order-auto-cancel/`
2. 在 WordPress 后台"插件"菜单中启用本插件
3. （可选）在 WooCommerce > 设置 > 支付 > 银行转账（BACS）中填写银行账户信息

---

## 配置说明

 
- **银行账户信息**：
  - 请在 WooCommerce 后台的 BACS 设置中填写银行账户信息，邮件会自动展示
- **自定义提醒时间**：
  - 可修改 `$reminder_hours` 和 `$cancel_hours` 属性自定义提醒和取消时间

---

## 邮件内容示例

- **提醒邮件标题**：
  - `⏰ URGENTE: Su pedido será cancelado en 48 horas - Pedido #12345`
  - `⏰ URGENTE: Su pedido será cancelado en 24 horas - Pedido #12345`
- **提醒邮件正文**：
  - 包含订单金额、剩余时间、银行信息、查看订单按钮、客服联系方式等
- **取消邮件标题**：
  - `❌ Pedido cancelado por falta de pago - Pedido #12345`
- **取消邮件正文**：
  - 表达遗憾、说明原因、鼓励客户重新下单、提供客服联系方式

---

## 常见问题 FAQ

**Q: 邮件没有银行信息？**
A: 请确保 WooCommerce > 设置 > 支付 > 银行转账（BACS）中填写了完整的银行账户信息。

 
**Q: 如何自定义提醒时间？**
A: 修改类属性 `$reminder_hours`（提醒点，单位小时）和 `$cancel_hours`（取消点，单位小时）。

**Q: 邮件内容可以自定义吗？**
A: 可以，直接修改插件中的 `send_reminder_email` 和 `cancel_order` 方法内容。

---

## 版本历史

- v1.0.0 首发版本，支持自动取消、分阶段提醒、银行信息展示、测试模式等功能

---

## 联系方式

如有问题或建议，请联系开发者：yitu 