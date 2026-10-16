# GEO Content Operations Platform

**Portfolio Edition · 虚构数据 · 本地模拟服务**

基于 FastAdmin / ThinkPHP 的企业内容运营工作台，将企业资料、问题策划、GEO 目标答案、AI 文章任务、媒体目录、发布订单和可见度跟踪组织成可追溯的工作流。

本版本用于求职展示。可运行的 Portfolio Edition 使用虚构企业、媒体、价格、余额和模拟可见度结果；默认不接入真实 AI 或付费媒体服务，不提供真实收录与排名保证。下方另收录经脱敏的原项目历史演示截图，用于展示实际开发的界面与业务流程。

## 项目背景

企业内容生产往往需要反复核对资料、修改提示词、确认文章版本、选择媒体并追踪订单。本项目将这些步骤串联起来，保留版本、任务、状态和资金流水，方便检查每一步发生了什么。

## 项目截图

以下画面截取自本人提供的 **2026-08-26 原项目演示录屏**，已裁去浏览器标签与地址栏，并遮挡公司名称、账号、网站、订单标识及具体报价。深色块为隐私遮挡；业务状态与正文未改写。历史界面与当前可运行的 Mock 展示版存在布局和数据差异。

| 内容生产 | 媒体接入与筛选 |
| --- | --- |
| ![原项目：生成后的文章正文与版本列表](docs/screenshots/original-demo/04-generated-article.jpg) | ![原项目：多渠道媒体目录与筛选](docs/screenshots/original-demo/05-media-filters.jpg) |
| ![原项目：知识素材与 AI 生成任务](docs/screenshots/original-demo/03-ai-task-input.jpg) | ![原项目：媒体搜索结果与选择](docs/screenshots/original-demo/06-media-results.jpg) |

完整的 8 张历史截图、时间点和能力边界见 [截图说明](docs/screenshots/README.md)。原 Mock 演示截图也保留在该目录，供本地运行时参考。

录屏中的订单状态为“待人工”，GEO 目标答案为“待审核”；不能据此声称该笔订单已发布或真实 AI 收录检测成功。媒体目录的“AI 收录”筛选项是目录属性，不代表本系统已验证收录效果。

## 核心功能

- 企业资料、文本知识和人工确认，联系方式加密存储。
- 关键词与问题管理，模拟 AI 问题生成和人工审核。
- 有来源引用校验的 GEO 目标答案、修订与确认版本。
- 异步文章任务、重试、历史版本及来源关联。
- 新闻、自媒体、短视频三个演示目录，支持筛选与收藏。
- 报价确认、幂等下单、模拟提交/同步、通知和订单时间线。
- 演示余额冻结、扣款、解冻和流水，保留账务边界。
- 发布前 Baseline、3/4/7 天计划、模拟可见度历史、趋势与优化建议。
- 多租户隔离、任务签名、审计与内部维护基础。

## 产品流程

```mermaid
flowchart LR
    A[企业资料与知识] --> B[问题生成与审核]
    B --> C[目标答案与事实确认]
    C --> D[异步文章与版本]
    D --> E[发布前 Baseline]
    E --> F[媒体报价与模拟发布]
    F --> G[发布后复检]
    G --> H[趋势与优化建议]
    H --> A
```

最后一步由人工调整资料与内容，不自动创建付费订单。模拟复检用可注入时间演示周期推进，不代表真实经过了 3 天或取得了推广效果。

## 技术架构

| 层 | 实现 |
| --- | --- |
| 企业工作台 | HTML / CSS / 原生 JavaScript |
| 内部管理 | FastAdmin 模板与权限基础 |
| 后端 | PHP 8 / ThinkPHP 5，模块化单体 |
| 数据 | MySQL 8，结构迁移、外键与唯一约束 |
| 异步 | Redis / Think Queue、独立 Worker、任务事实表 |
| 外部边界 | AI Provider / Supplier Adapter / Storage / Visibility 接口及本地演示实现 |

第三方来源及分发边界见 [来源说明](NOTICE.md)。

详见 [技术架构](docs/ARCHITECTURE.md) 与 [数据模型](docs/DATA_MODEL.md)。

## 我的具体工作

在负责人提供的初步需求、参考方案和业务思路基础上，独立完成 GEO 企业内容运营 SaaS 的开发与落地，将初步方案转化为可运行系统。负责前后端业务开发、数据库设计、公司提供的上游 API 接入、接口联调、测试与交付整理。项目基于 FastAdmin / ThinkPHP 构建，开发过程中使用 AI 工具辅助。

负责人提供项目方向、初始资料和业务思路，公司提供上游 API 资源；项目侧接口接入与业务系统开发由本人完成。FastAdmin、ThinkPHP 及其他开源依赖沿用各自的技术来源与许可。

| 工作范围 | 本人完成的内容 |
| --- | --- |
| 需求落实与业务流程实现 | 依据负责人提供的初步需求和业务思路，将企业资料、知识、问题、目标答案、内容生产、发布订单与可见度跟踪落实为系统功能 |
| 前端工作台 | 实现企业资料、内容生产、媒体筛选、订单财务和 GEO 时间线等页面及交互 |
| 数据库与后端 | 设计业务数据结构和迁移，实现多租户隔离、文章版本、异步任务、发布状态和账务服务 |
| 外部 API 集成 | 对接公司提供的上游 API，完成项目侧的鉴权配置、请求与响应适配、状态映射、错误处理及业务联动 |
| 稳定性与验证 | 实现任务与订单幂等、失败处理、重复事件保护、余额冻结/扣款/解冻及相关测试 |
| 求职演示交付 | 整理独立脱敏副本、本地 Mock、合成数据、初始化脚本、截图及运行文档 |

职责范围依据本人于 2026-10-16 的补充说明更新：负责人提供初步需求、参考方案和业务思路，本人独立负责开发与落地。功能实现与运行验证的具体边界见下方成果及验证记录。简历文案和面试介绍见 [求职表达材料](docs/CAREER_MATERIALS.md)。

## 项目成果与证据

本演示版以可重复操作和测试为证据，不虚构客户、收入、ROI 或 AI 推荐提升。原项目仅公开经脱敏的历史界面截图；真实 API 请求记录、原始订单和客户数据不分发，不能将截图或模拟结果替代真实业务验收。

- 新空库结构迁移、两套虚构租户与幂等初始化。
- 问题/答案/文章/建议四类演示 AI 契约。
- 发布前 Baseline、模拟发布扣款、退稿解冻、趋势建议。
- 运行守卫拒绝真实驱动、原业务库及危险环境变量覆盖。

实际验证范围及未完成项见 [验证记录](docs/VERIFICATION.md)。

## 本地运行（Windows）

要求：PHP 8.0.2 及 bcmath/curl/mbstring/pdo_mysql/redis/openssl/fileinfo 等扩展、Composer、Node/npm、MySQL 8 和本机 Redis。Redis 14 号库必须专用于本演示，初始化不会清空 Redis。

```powershell
git clone https://github.com/zfc1538979761-stack/geo-saas-portfolio.git
cd geo-saas-portfolio
composer install --no-interaction --prefer-dist --no-scripts
npm ci --ignore-scripts --no-audit --no-fund
npm run build
php scripts/setup-portfolio.php
```

本地随机密钥及数据库密码只写入被忽略的 `.env`。已有配置不会覆盖。

使用一套新 MySQL 实例，禁止复用原业务数据库。以下命令需将安装目录替换为本机实际路径：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-portfolio-db.ps1 -MysqlBase '<本机 MySQL 8 安装目录>'
php scripts/create-portfolio-database.php
php scripts/seed-portfolio.php
php scripts/demo-workflow.php
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/run-portfolio.ps1
```

新实例仅监听 `127.0.0.1:33329`，数据保存在本项目 `runtime/mysql`。旧 MySQL 在中文路径下可能需要脚本提供的 P: ASCII 映射；P: 已被其他目录使用时脚本会拒绝。

打开 `http://127.0.0.1:8877/index/enterprise/index`。虚构账号 `portfolio_demo_a`、`portfolio_demo_b`，演示密码均为 `PortfolioDemo123!`。这些是公开演示凭据，不得复用到真实系统。

停止由启动器管理的 Web/Worker：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/stop-portfolio.ps1
```

该命令不停止其他项目、MySQL 或 Redis。演示步骤见 [DEMO_GUIDE](docs/DEMO_GUIDE.md)。

## 测试

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/secret-scan.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/verify-portfolio.ps1 -IncludeHttp
# 进程故障测试需先停止 Web/Worker：
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/stop-portfolio.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/portfolio/lifecycle_test.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/run-portfolio.ps1
```

遗留测试包含原版本专用契约和安全门禁，未运行部分不计入当前验收。禁止对原数据库运行迁移、清理或测试。

## 已知限制

- 没有真实联网可见度检测或 AI 收录效果验证。
- 以桌面浏览器演示为主；390px 手机布局仍有横向溢出。
- 保留旧版框架及依赖，未做生产部署或全面依赖安全升级。
- 没有独立的图片生成、视频生成或剪辑能力。
- 本地演示存储不等同于生产 OSS。
- 模拟账务不等于在线支付；真实供应商回调、取消及退款不在本版能力内。
- 企业联网发现保持不可用，演示通过人工资料继续。
- 本版按项目参与者授权整理为求职展示源码，已排除公司密钥、真实接口配置、客户数据和真实订单记录。
- 未核验来源的53项原图片/字体未随本版分发；企业工作台使用系统字体与CSS标识，旧管理模板的可选资源不计入本版演示验收。

## 脱敏与技术来源

本副本不包含原 Git 历史、真实环境配置、客户数据库、真实订单原始记录或私有集成实现。历史录屏截图只分发经遮挡的界面画面，原视频、未脱敏截帧及 OCR 文件不上传。新增本地配置、日志、缓存和数据目录均不作为发布内容。

基于 FastAdmin 与 ThinkPHP，保留仓库 LICENSE 和依赖许可。现有许可文件不替代公司定制代码公开授权确认。
