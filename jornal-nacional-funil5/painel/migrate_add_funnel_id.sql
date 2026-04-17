-- Execute este SQL no banco japadaloto_analytics para adicionar suporte a Funil 1 e Funil 2
-- Exemplo: mysql -u jap_analytics -p japadaloto_analytics < migrate_add_funnel_id.sql

ALTER TABLE pageviews
ADD COLUMN funnel_id TINYINT NOT NULL DEFAULT 1
COMMENT '1=jornal-nacional (funil 1), 2=jornal-nacional-funil2 (funil 2)'
AFTER device_type;

CREATE INDEX idx_pageviews_funnel ON pageviews(funnel_id);
