<?php
/*
这是最简单的通过检查 QQ 群成员进行身份验证的程序，您可以通过修改其中出现的群号和群成员在群内的 LV 级别，将其用在您的群。

当访问到该程序，程序会验证客户端是否具有访问所需的 Cookie，如果没有 Cookie，或者它已经过期，或者已被篡改，程序会要求进行身份验证。

该程序只能运行在 Linux + Nginx + PHP 架构上。

请根据您的使用场景修改待加密字串的前缀和后缀，不应使用默认值，否则会有安全风险。您也可以修改代码，从而改用更强的哈希算法，我群采用 CRC32 已经足以。

使用方法：将此文件命名为 login.php，放在网站根目录。在要认证的文件的头部的 <?php 后加上以下代码：

require_once ($_SERVER['DOCUMENT_ROOT']."/login.php");

别忘了在网站根目录创建 log 文件夹，并赋予适当权限，用来存放身份验证文件及登录日志。其外，Go-CQHTTP 用来登录的 QQ 必须具有该群的管理员权限，否则无法发送临时会话。

有任何使用上的问题，可以加群讨论，群号在代码当中。此外，本群以视障学生为主。

本程序经过多次迭代，其中的变量可能看起来有点乱，不要介意。

*/

define ("prefix","prefix"); // 在要加密的字串前面加上前缀，相当于撒盐。
define ("postfix","postfix"); // 在要加密的字串后面加上后缀，相当于撒盐。
define ("filename_postfix", "_filename"); // 临时认证文件名称的后缀，防止未经授权的下载。
define ("cookie_valid_period", 604800); // 认证 Cookie 的最长有效时间。
define ("cqhttp_accesskey", "accesskey"); // CQHTTP 的访问令牌
define ("inviteurl_prefix", "https://example.com/login.php"); // 邀请 URL 的前缀，请修改成您网站的域名。

$stored_access_key = "12345678"; // 设定预共享密钥（PSK），以防止群外人员骚扰群成员。

// Get client IP address
if (isset ($_SERVER['HTTP_X_FORWARDED_FOR'])) {
$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
} else {
$client_ip = $_SERVER['REMOTE_ADDR'];
}

// Encrypt/Decrypt function
function encrypt_decrypt ($encrypt_decrypt_action, $string) {
$output = false;
if ($encrypt_decrypt_action == 'encrypt' ) {
$output = crc32 (prefix.$string.postfix);
} elseif ( $encrypt_decrypt_action == 'decrypt' ) {
echo "这种算法无法解密。";
exit();
}
return $output;
}

// Redirection URL
if (isset ($_GET['referer'])) {
$referer = $_GET['referer'];
} elseif (isset ($_POST['referer'])) {
$referer = $_POST['referer'];
} else {
$referer = $_SERVER['REQUEST_URI'];
}
// Avoid loop redirection
if (strpos ($referer,'/login.php') !== false) {
$referer ="/";
}

// Login form function
function login_form() {
$referer = $_GET['referer'];
setcookie ("viyf365_auth","");
global $client_ip;

print <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>身份验证</title>
</head>
<body>
<h1>要继续，请完成身份验证</h1>
<div align="center" style="width:100%;">
<p>您的 IP 地址是 $client_ip</p>
<form method="post" charset="utf-8">
<div style="width:500px; height:50px;">
<p>预共享密钥 (PSK)</p>
<p><input name="token" type="password" maxlength="100" autocomplete="off" title="预共享密钥 (PSK)" autofocus="autofocus" value="" /></p>
</div>
<div style="width:500px; height:50px;">
<p>您的 QQ</p>
<p><input name="qq" type="text" maxlength="13" autocomplete="off" title="您的 QQ" value="" /></p>
</div>
<input name="action" type="hidden" value="loginprocessor" />
<input name="referer" type="hidden" value="$referer" />
<div style="width:500px; height:50px;">
<p><input type="submit" value="Go!" /></p>
</div>
</form>
</div>
</body>
</html>
HTML;
exit();
}

// Login processor function
function login_processor() {
// Fetch and set variable
global $stored_access_key, $client_ip, $referer;
$current_date = date ("Y-m-d");
$current_time = date ("H:i:s");

// Checking access token
if (!isset($_POST['token'])) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
if ($_POST['token'] !== $stored_access_key) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}

// Checking QQ number
if (!isset($_POST['qq'])) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
$qq = $_POST['qq'];
$qq_length = strlen ($qq);
if ($qq_length < 5 || $qq_length > 13) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
/*
if (is_int ($qq) != TRUE) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
*/

// Checking user permission
// 获取群成员列表
$group_members_array = json_decode (file_get_contents ("http://127.0.0.1:5701/get_group_member_list?access_token=".cqhttp_accesskey."&group_id=493728167"),true);
$group_members_list_array = $group_members_array['data'];
// 统计会员人数，由于数组成员序号从 0 开始，所以总数应减去 1
$group_members_count_number = count ($group_members_list_array) - 1;
// 初始化有资格的用户列表数组
$group_qualified_list_array = [];
// 从成员列表中找出级别大于 1 的成员
for ($i=0; $i<=$group_members_count_number; $i++) {
if ($group_members_list_array[$i]['level'] > 1) {
$group_qualified_list_array[$group_members_list_array[$i]['user_id']] = 'qualified';
}
}
// 判断输入的用户，是否在有资格的用户列表数组中
if (!array_key_exists ($qq,$group_qualified_list_array)) {
// 防止开发者被挡住
if ($qq != 420390174) {
echo "您的级别不够。";
exit();
}
}

// Generating verify file
$current_time = time();
$valid_period = time()+300;
$hash_text = encrypt_decrypt ("encrypt",$qq.$client_ip);
$verify_file = "log/verify_".$qq.filename_postfix.".auth";
if (is_file ($verify_file)) {
$verify_content = json_decode (file_get_contents ($verify_file),true);
if ($verify_content['valid_period'] < $current_time) {
unlink ($verify_file);
} else {
echo "校园助理已经告诉您如何访问了哦，请您根据校园助理的消息，完成身份验证，谢谢~~";
exit();
}
}
// Create verify array
$verify_content_array = ["valid_period" => $valid_period, "client_ip" => $client_ip, "hash" => $hash_text];
$verify_content_array = json_encode ($verify_content_array);
file_put_contents ($verify_file,$verify_content_array);
// Send a invite
$invite_url = urlencode (inviteurl_prefix."?action=loginverify&qq=".$qq."&hash=".$hash_text."&referer=".$referer);
$message = urlencode ("您好，您正在进行校园设施身份认证，请将下面的链接复制到浏览器中打开，如果您是从 QQ 进入校园的身份验证网页，请直接点击下面的链接，谢谢！");
file_get_contents ("http://127.0.0.1:5701/send_private_msg?access_token=".cqhttp_accesskey."&group_id=493728167&user_id=".$qq."&message=".$message);
file_get_contents ("http://127.0.0.1:5701/send_private_msg?access_token=".cqhttp_accesskey."&group_id=493728167&user_id=".$qq."&message=".$invite_url);
echo "请打开您的 QQ，然后根据校园助理的消息完成后续认证。";
exit();
}

function login_verify() {
// Fetch and set variable
global $client_ip,$referer;
$current_date = date ("Y-m-d");
$current_his = date ("H:i:s");
$current_time = time();
$recheck_ip_timestamp = time()+28800;
$recheck_ip_timestamp_hash = encrypt_decrypt("encrypt",$recheck_ip_timestamp);
$valid_timestamp = time()+cookie_valid_period;
$valid_timestamp_hash = encrypt_decrypt ("encrypt",$valid_timestamp);
$client_ip_crc32 = crc32 ($client_ip);
if (!isset ($_GET['qq']) || !isset ($_GET['hash']) || empty ($_GET['qq']) || empty ($_GET['hash'])) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
$qq = $_GET['qq'];
$hash = $_GET['hash'];
if (!isset ($_GET['referer']) || empty ($_GET['referer'])) {
$referer = "/";
}
$referer = $_GET['referer'];
// Confirm request
if (!isset ($_POST['confirm'])) {
print <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>确认您的请求</title>
</head>
<body>
<h1>就要大功告成了！</h1>
<div align="center" style="width:100%;">
<p>请点击下面的按钮确认您的请求。</p>
<form method="post" charset="utf-8">
<input name="action" type="hidden" value="loginverify" />
<input name="qq" type="hidden" value="$qq" />
<input name="hash" type="hidden" value="$hash" />
<input name="referer" type="hidden" value="$referer" />
<input name="confirm" type="hidden" value="yes" />
<div style="width:500px; height:50px;">
<b><p><input type="submit" value="确认，我完成身份验证啦！" /></p></b>
</div>
</form>
</div>
</body>
</html>
HTML;
exit();
}

// Checking input data
$verify_file = "log/verify_".$qq.filename_postfix.".auth";
if (is_file ($verify_file)) {
$verify_content = json_decode (file_get_contents ($verify_file),true);
} else {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
if ($verify_content['valid_period'] < $current_time) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
if ($verify_content['client_ip'] != $client_ip) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
if ($verify_content['hash'] != $hash) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}

// Issue access cookie
// Cookie content array
$auth_cookie_content = [
"recheck_ip_timestamp" => $recheck_ip_timestamp,
"recheck_ip_timestamp_hash" => $recheck_ip_timestamp_hash,
"valid_timestamp" => $current_time+cookie_valid_period,
"valid_timestamp_hash" => $valid_timestamp_hash,
"client_ip_hash" => encrypt_decrypt ("encrypt", $client_ip_crc32)
];
$auth_cookie_content = json_encode ($auth_cookie_content);

// Issue the cookie
file_put_contents ($_SERVER['DOCUMENT_ROOT']."/log/login-".$current_date.filename_postfix.".log",$current_his.", ".$qq.", ".$client_ip."\r\n",FILE_APPEND);
setcookie ("viyf365_auth", $auth_cookie_content, time()+cookie_valid_period);
unlink ($verify_file);
header("Location: ".$referer);
exit();
}

// authenticator
$client_ip_crc32 = crc32 ($client_ip);

if (isset($_POST['action'])) {
$user_action = $_POST['action'];
} elseif (isset($_GET['action'])) {
$user_action = $_GET['action'];
}
if (isset ($user_action)) {
if ($user_action == "loginform") {
login_form();
} elseif ($user_action == "loginprocessor") {
login_processor();
} elseif ($user_action == "loginverify") {
login_verify();
}
}

if (isset($_COOKIE['viyf365_auth'])) {
$auth_cookie_content = json_decode ($_COOKIE['viyf365_auth'],true);
$recheck_ip_timestamp = $auth_cookie_content['recheck_ip_timestamp'];
$recheck_ip_timestamp_hash = $auth_cookie_content['recheck_ip_timestamp_hash'];
$valid_timestamp = $auth_cookie_content['valid_timestamp'];
$valid_timestamp_hash = $auth_cookie_content['valid_timestamp_hash'];
$client_ip_hash = $auth_cookie_content['client_ip_hash'];
$is_viyfschooluserinfo = "yes";
} else {
$is_viyfschooluserinfo = "no";
}

if ($is_viyfschooluserinfo == "no") {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}

// Checking timestamp and IP address
// If recheck timer was passed, check valid timestamp and IP address
if ($recheck_ip_timestamp > time()) {
if ($valid_timestamp_hash != encrypt_decrypt ("encrypt", $valid_timestamp)) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
if ($valid_timestamp < time() || $valid_timestamp_hash != encrypt_decrypt ("encrypt", $valid_timestamp)) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}
} else {
if ($client_ip_hash != encrypt_decrypt ("encrypt",$client_ip_crc32)) {
header("Location: /login.php?action=loginform&referer=".$referer);
exit();
}

// Renewing cookie
$new_auth_cookie_content = [
"recheck_ip_timestamp" => $recheck_ip_timestamp+43200,
"recheck_ip_timestamp_hash" => $recheck_ip_timestamp_hash,
"valid_timestamp" => $valid_timestamp,
"valid_timestamp_hash" => $valid_timestamp_hash,
"client_ip_hash" => $client_ip_hash,
];
$new_auth_cookie_content = json_encode ($new_auth_cookie_content);
setcookie ("viyf365_auth", $new_auth_cookie_content, time()+cookie_valid_period);
}

?>
