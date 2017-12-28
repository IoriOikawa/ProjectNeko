# ProjectNeko
基于PHP的一个内网穿透的坑

# 环境需求

 - Swoole
 - PHP 7.0+
 
# 示例

![example.png](https://i.loli.net/2017/12/28/5a44c900148b7.png)

## 服务端 

`sudo php server.php -p password`

## 客户端

`sudo php neko.php -l {localip}:{localport} -r {remote server}:{remote port} -p password`
