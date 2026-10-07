#!/usr/bin/env bash
# The automatic-migration guard of deploy.sh, against SQL as `migrate --pretend` prints it:
# additive statements pass (also when a name contains a keyword), changing ones are caught.
# Run by CI (job web-config) and by hand: bash deploy/check-migration-guard.sh
set -euo pipefail
cd "$(dirname "$0")"
fail=0

check() { # check <safe|risky> <sql>
    found=$(printf '%s\n' "$2" | bash deploy.sh --risky-sql)
    if [ "$1" = safe ] && [ -n "${found}" ]; then echo "FAIL (flagged, but only adds): $2"; echo "     -> ${found}"; fail=1; fi
    if [ "$1" = risky ] && [ -z "${found}" ]; then echo "FAIL (not flagged): $2"; fail=1; fi
}

# Stopped a deploy on 2026-10-07: the table is called change_logs.
check safe '2026_10_06_000003_create_change_logs_table ......................................'
check safe 'create table `change_logs` (`id` bigint unsigned not null auto_increment primary key, `shop_id` bigint unsigned not null)'
check safe 'alter table `change_logs` add constraint `change_logs_shop_id_foreign` foreign key (`shop_id`) references `shops` (`id`) on delete cascade'
check safe 'alter table `change_logs` add index `change_logs_created_at_index`(`created_at`)'
check safe 'alter table `variants` add `rename_count` int not null default 0, add `update` varchar(255) null'
check safe 'alter table `orders` add constraint `orders_shop_id_foreign` foreign key (`shop_id`) references `shops` (`id`) on delete set null on update cascade'
check safe '2026_10_01_000001_add_drop_ship_to_suppliers ....................................'

check risky 'alter table `variants` drop `barcode`'
check risky 'alter table `variants` drop column `barcode`'
check risky 'drop table `change_logs`'
check risky 'drop table if exists `old_things`'
check risky 'alter table `variants` rename column `cost` to `unit_cost`'
check risky 'rename table `a` to `b`'
check risky 'alter table `variants` modify `sku` varchar(100) not null'
check risky 'alter table `variants` change `sku` `code` varchar(100) not null'
check risky 'truncate table `daily_sales`'
check risky 'delete from `forecasts` where `shop_id` = 1'
check risky 'update `variants` set `unit_cost` = 0'
check risky 'ALTER TABLE `variants` DROP INDEX `variants_sku_index`'

[ "${fail}" = 0 ] && echo "Migration guard: ok"
exit "${fail}"
