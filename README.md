# phjs — JavaScript 沙箱 VM（PHP 实现）

用 PHP 实现的隔离 JS 运行环境：在 chroot 根文件系统内运行**完整 Node.js**
（fs/path/http/net/child_process 等全部功能），文件系统与主机隔离，
项目目录通过 bind-mount 共享为 `/app`，支持 `npm` / `npx` 安装包，
内置交互 shell 和 Node REPL。

```
PHP 调起进程 ──> mount(proc, bind) ──> fork ──> chroot(隔离根文件系统)
                                                       │
                                         /app = 项目目录 (bind)
                                         /usr/bin/node (真实 Node)
                                         /usr/bin/npm  (真实 npm)
```

## 特性

- **文件系统隔离**：chroot 最小 rootfs（node + npm + bash + coreutils + glibc），
  沙箱内看不到 `/data`、`/root`、`/etc/shadow` 等主机文件
- **完整 Node.js**：动态链接的系统 Node 直接拷入沙箱，无任何功能阉割
- **npm 生态**：`phjs npm install xxx`，包落在项目的 `node_modules`（即沙箱 `/app`）
- **项目共享**：`/app` 与项目目录互为镜像（bind mount），双向实时同步
- **shell / REPL**：`phjs shell`（bash）、`phjs repl`（node REPL）
- **可选特权降级**：`--nobody` 以 uid 65534 运行
- **超时控制**：`--timeout SEC`，超时后以 SIGALRM 终止（退出码 128+14）

## 要求

- Linux + root 权限（chroot / mount 需要）
- PHP CLI ≥ 8.0，扩展：`pcntl`、`posix`
- 主机装有 Node.js（沙箱根文件系统从主机复制，一次构建，之后不依赖主机运行）
- 网络连通（npm 下载需要）

## 安装

```bash
ln -s /data/workspace/phjs/phjs /usr/local/bin/phjs   # 进 PATH
phjs init                                             # 构建沙箱 rootfs（~/.phjs/rootfs）
phjs init --with-git                                  # 附带 git（支持 npm git 依赖）
phjs init --with-gcc                                  # 附带 gcc/make/python3（node-gyp 原生模块，实验性）
```

## 使用

```bash
# 在任意项目目录运行 JS（/app 自动绑定到当前目录）
phjs run hello.js arg1 arg2
echo 'console.log(1+1)' | phjs run -        # 从 stdin 读脚本

# npm / npx（工作目录 = /app，即项目目录）
phjs npm install express
phjs npm run build
phjs npx cowsay hi

# 交互环境
phjs shell                                   # 沙箱内 bash
phjs repl                                    # 沙箱内 Node REPL
phjs node --version                          # 直接跑 node

# 其它
phjs exec 'ls / && node -v'                  # 任意命令
phjs --nobody run app.js                     # nobody 权限
phjs --timeout 10 run app.js                 # 10 秒超时
phjs --root /tmp/sandbox2 init               # 自定义 rootfs 位置（多个沙箱）
phjs info                                    # 查看状态
phjs uninstall                               # 删除沙箱
```

## 命令

| 命令 | 说明 |
|---|---|
| `init [--with-git] [--with-gcc]` | 构建隔离 rootfs |
| `run <file.js> [args]` | 沙箱内运行 JS（`-` 表示 stdin） |
| `node <args>` | 沙箱内直接执行 node |
| `repl` | 沙箱内 Node REPL |
| `shell [cmd...]` | 沙箱内 bash（无参数时交互式） |
| `npm / npx <args>` | 沙箱内 npm / npx |
| `exec <cmd...>` | 沙箱内任意命令 |
| `info` / `uninstall` | 状态 / 卸载 |

全局选项（放命令前）：`--root DIR`、`--project DIR`、`--nobody`、`--timeout SEC`

## 隔离模型与边界

- 根文件系统：chroot 后只能看到 rootfs（`~/.phjs/rootfs` 或 `--root`）
- 项目目录：bind-mount 为 `/app`，**双向可见**（`rm -rf /app/*` 会删主机文件，注意）
- 网络：默认共享主机网络（DNS 通过复制的 resolv.conf）
- 进程：非隔离（不限制对主机的进程/网络/内核的访问）——若要进程隔离，
  可配合 `systemd-run --scope -p MemoryMax=...` 或 docker 使用
- 沙箱内 `rm /` 之类操作只影响 rootfs（node_modules、`/app` 除外）

# 请求服务模式（要求 2：纯 PHP 参数驱动，单用户串行 + 状态保存）

不依赖任何 PHP 插件/扩展（仅 php + pcntl/posix 内置扩展），请求到达 →
认证 → `php run` 执行 → 完成后保存状态 → 等待下一个用户。

```bash
# 1) CLI 单次请求（把请求当参数传给 php）
phjs serve "user=root&password=123456&nodecommand=hello.js --help"

# 2) stdin 循环（每行一个请求，处理完保存状态，继续等待下一行）
echo 'user=root&password=123456&nodecommand=app.js' | phjs serve --stdin

# 3) HTTP 服务（php -S 内置服务器，本身单线程天然串行）
phjs serve --port 8090
curl "http://host:8090/?user=root&password=123456&nodecommand=hello.js%20--help"

# 4) 内联代码（code= 参数，写入用户状态目录后执行）
phjs serve "user=root&password=123456&code=console.log(1%2B2)"

# 附加 format=text 直接拿纯文本输出（退出码在 X-Phjs-Exit 头）
curl "http://host:8090/?user=root&password=123456&nodecommand=hello.js&format=text"
```

## 请求参数

| 参数 | 说明 |
|---|---|
| `user` | 用户名（必填） |
| `password` | 密码（必填） |
| `nodecommand` | `script.js [args...]`（引号内参数会被识别；空格需 %20，`+` 需 %2B） |
| `code` | 直接给 JS 代码（与 nodecommand 二选一） |
| `format` | `json`（默认）/ `text` |

## 状态保存（完成操作后自动持久化）

每个用户拥有独立的持久化状态目录 `~/.phjs/users/<user>/`：

```
~/.phjs/users/root/
├── app/              # 沙箱 /app（该用户所有文件、node_modules，跨请求持久）
├── state.json        # 最近一次操作结果 {command, exit, stdout, stderr, ts, durationMs}
└── history.log       # 全部操作历史（每行一条 JSON）
```

每次请求流程：认证 → 抢全局锁（flock，多个请求排队等待，`waitedMs` 记录等待时间）
→ 恢复该用户状态目录 → 沙箱内执行 → 结果写回 `state.json`/`history.log` →
释放锁。文件状态天然持久（bind mount 到宿主磁盘），服务重启不丢失。

## 用户认证

```bash
# 方式一：命令/环境变量
PHJS_AUTH='root=123456,alice=abc' phjs serve --port 8090
phjs serve --port 8090                                   # 读环境变量 PHJS_AUTH

# 方式二：配置文件 ~/.phjs/users.conf（一行: user=pass,user2=pass2）
# 未配置时默认 root/123456（会在启动时警告）
```

## 排队的验证

```bash
# 两个请求同时进来，第二个 waitedMs > 0（等待第一个完成）
phjs serve "user=root&password=123456&code=setTimeout(()=>console.log(1),3000)" &
phjs serve "user=root&password=123456&code=console.log(2)" &
# 第一个 "waitedMs":0  第二个 "waitedMs":~3000
```

## 工作原理

1. `init` 构建最小 rootfs：从主机硬链接/复制 node、npm 包、bash 与 coreutils，
   通过 `ldd` 解析并复制 glibc 动态库、`/dev` 设备节点、`/etc`（passwd/hosts/resolv/CA 证书）
2. 每次执行：父进程 `mount -t proc` + `mount --bind 项目 → /app`，fork 子进程
   `chroot` 后 `exec`，父进程转发信号并在子进程退出后卸载挂载点
3. 退出码原样透传（信号退出为 128+信号值）

## 项目结构

```
phjs/
├── phjs                     # 入口（PHP CLI）
├── src/Phjs/
│   ├── Cli.php              # 参数解析与命令分发
│   ├── Sandbox.php          # rootfs 构建 + chroot 执行 + 挂载管理
│   └── Logger.php           # 日志
└── templates/package.json   # 沙箱 /app 初始 package.json
```
