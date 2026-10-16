# 数据模型

| 业务 | 核心表 |
| --- | --- |
| 企业与身份 | companies、user、company_profiles、user_token |
| 内容 | keywords → questions → articles → article_versions |
| 知识与提示词 | knowledge_items、prompt_templates、article_version_knowledge、oss_file_indexes |
| AI 事实 | geo_async_jobs、ai_calls |
| 媒体发布 | suppliers、channels、media_resources、publish_orders、publish_order_items、order_status_logs |
| 账务 | wallets、ledger_entries、publish_refund_requests |
| GEO | geo_answer_versions、geo_visibility_plans/checks/results/comparisons、geo_optimization_suggestions |
| 维护 | audit_logs、通知、健康/告警/备份记录 |

企业资源带 company_id，外键及联合唯一键维护归属。媒体目录为共享目录，收藏属于租户。文章版本、知识快照与订单引用固定版本，避免后续编辑改写历史。

演示初始化使用单独的全局标记表 portfolio_seed/portfolio_workflow 保存已完成的初始化阶段，仅存在于独立演示库。原项目数据库和业务数据从未导入。
