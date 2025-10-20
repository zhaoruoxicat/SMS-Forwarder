# 📩 SMS Forwarder — 短信转发与接收中心

> 一款基于 **PHP + MySQL + PWA** 的轻量级短信收集、转发与管理系统。  
> 适用于树莓派 4G 模块、安卓短信转发器、或任何可发起 HTTP 请求的设备，将短信统一保存到云端并可在网页端方便查看。

---

## 🌟 功能特性

- 📬 **短信接收 API**
  
  - 支持 `application/json` 与 `application/x-www-form-urlencoded` 两种请求格式；
  - 自动识别字段别名（`phone/sender/from`、`content/message/body` 等）；
  - 自动规范化时间（时间戳 / 日期字符串 / 空值自动填充当前时间）；
  - 支持多设备上报（字段 `device`）；
  - 内置 Token 鉴权机制。

- 💬 **网页端短信记录查看（PWA）**
  
  - 网页端支持安装为 **PWA（渐进式 Web 应用）**；
  - 首次访问时可点击“添加到主屏幕”；
  - 进入后为简洁的“气泡聊天”式界面，适配手机屏幕；
  - 可方便查看历史短信记录，一键复制短信验证码，一键拨打短信中中国大陆手机号码，点击访问短信中的网址链接。

- 🔑 **Token 管理页面**
  
  - 后台提供 Token 管理界面，可添加、启用/禁用或删除 Token
  - 每个 Token 可独立绑定客户端（如树莓派、安卓端）

- 🗑️ **一键清空短信记录**
  
  - 提供“删除全部短信”功能，可由管理员一键清空数据库表；
  - 用于测试或应急清除敏感信息；
  - 操作带确认步骤，防止误删。

- ⚙️ **安装向导**
  
  - 浏览器访问 `/install/install.php` 自动导入数据库结构；
  - 一键创建管理员账户；
  - 自动生成 `db.php` 数据库连接文件；
  - 安装后删除`/install/`文件夹

---

## 🖥️ 系统要求

| 组件      | 推荐版本                         |
| ------- | ---------------------------- |
| PHP     | ≥ 8.0（需开启 PDO MySQL 扩展）      |
| MySQL   | ≥ 5.7                        |
| Web 服务器 | Apache / Nginx  任意支持 PHP 的环境 |

---

## 🚀 快速安装

1. **上传文件**
   将项目上传到服务器目录（如 `/www/wwwroot/sms.example.com/`）。

2. **运行安装向导**
   浏览器访问：  
   `你的域名/install/install.php`  
   
   填写数据库信息、管理员账号密码； 
   
   安装完成后删除 `install/` 目录。

---

## 📡 API 使用说明

### 接口地址

```
POST https://yourdomain.com/api_sms_receive.php
```

### 参数说明

| 参数        | 类型         | 必填  | 说明         |
| --------- | ---------- | --- | ---------- |
| `token`   | string     | ✅   | 授权令牌       |
| `phone`   | string     | ✅   | 发信号码       |
| `content` | string     | ✅   | 短信正文       |
| `time`    | string/int | ❌   | 接收时间（默认当前） |
| `device`  | string     | ❌   | 来源设备标识     |

### 响应示例

成功：

```json
{"success":true}
```

失败：

```json
{"success":false,"error":"invalid token"}
```

---

## 🧪 Bash 测试命令

### JSON 请求

```bash
curl -X POST "https://yourdomain.com/api_sms_receive.php?token=YOURTOKEN"   -H "Content-Type: application/json"   -d '{
    "phone": "+8613812345678",
    "content": "【测试】这是一条短信转发接口测试消息。",
    "device": "RaspberryPi-4G"
  }'
```

### 表单请求

```bash
curl -X POST "https://yourdomain.com/api_sms_receive.php"   -d "token=YOURTOKEN"   -d "phone=13812345678"   -d "content=短信转发接口测试成功"   -d "device=TestDevice"
```

## 🔐 安全建议

- 安装完成后立即删除 `/install/` 目录；
- Token 建议使用随机高强度字符串；
- 使用“一键删除全部短信”功能可快速清空记录。

---

## 📲 安卓端短信转发说明

- 可在安卓端使用开源项目 **[SmsForwarder](https://github.com/pppscn/SmsForwarder)** 实现短信自动转发。  
- 在 APP 设置中选择 **Webhook 转发方式**，将目标地址填写为本项目部署的接口地址，例如：https://yourdomain.com/api_sms_receive.php?token=YOURTOKEN
- 即可让安卓手机接收到的短信自动上报至服务器保存。

📘 **详细教程参考：**  
项目链接：[https://github.com/pppscn/SmsForwarder](https://github.com/pppscn/SmsForwarder)  
或在网络上搜索 “SmsForwarder 短信转发 Webhook 教程” 获取详细步骤。

⚠️ **注意事项：**

- 国产安卓系统（如 MIUI、ColorOS 等）默认对验证码短信有保护策略，若要转发验证码类短信，请在系统设置中关闭：验证码短信保护、通知短信保护。以及授予短信读取与通知转发权限。
- 建议将转发应用加入后台白名单，以防止系统自动休眠导致转发中断。

## 📱 PWA 使用说明

- 首次访问网页时浏览器会提示“添加到主屏幕”；  
- 安装后可直接以 **气泡聊天视图** 打开短信记录；  
- 移动端访问默认进入 **PWA 移动视图**，便于查看短信内容；  

---

## 🔑 Token 管理

- 页面路径：`/token_manage.php`  
- 功能包括：
  - 查看全部 Token；
  - 启用 / 禁用；
  - 添加新 Token；
  - 删除无效 Token。

---

### 🌟 如果这个项目对你有帮助，请 Star ⭐ 支持！
