<?php
$content = file_get_contents('application/modules/dashboard/controllers/Module.php');

$search1 = <<<EOD
							foreach(\$install_queries as \$i_sql) {
								\$i_sql = trim(\$i_sql);
								if (!empty(\$i_sql)) {
									\$this->db->query("SET FOREIGN_KEY_CHECKS = 0");
									\$this->db->query(\$i_sql);
									\$this->db->query("SET FOREIGN_KEY_CHECKS = 1");
								}
							}
EOD;

$replace1 = <<<EOD
							foreach(\$install_queries as \$i_sql) {
								\$i_sql = trim(\$i_sql);
								if (!empty(\$i_sql)) {
									try {
										\$this->db->query("SET FOREIGN_KEY_CHECKS = 0");
										@\$this->db->query(\$i_sql);
										\$this->db->query("SET FOREIGN_KEY_CHECKS = 1");
									} catch (\Exception \$e) {
										// Ignore SQL errors like duplicates
									}
								}
							}
EOD;

$search2 = <<<EOD
				foreach(\$uninstall_queries as \$u_sql) {
					\$u_sql = trim(\$u_sql);
					if (!empty(\$u_sql)) {
						\$this->db->query("SET FOREIGN_KEY_CHECKS = 0");
						\$this->db->query(\$u_sql);
						\$this->db->query("SET FOREIGN_KEY_CHECKS = 1");
					}
				}
EOD;

$replace2 = <<<EOD
				foreach(\$uninstall_queries as \$u_sql) {
					\$u_sql = trim(\$u_sql);
					if (!empty(\$u_sql)) {
						try {
							\$this->db->query("SET FOREIGN_KEY_CHECKS = 0");
							@\$this->db->query(\$u_sql);
							\$this->db->query("SET FOREIGN_KEY_CHECKS = 1");
						} catch (\Exception \$e) {
							// Ignore SQL errors like non-existent keys
						}
					}
				}
EOD;

$content = str_replace($search1, $replace1, $content);
$content = str_replace($search2, $replace2, $content);
file_put_contents('application/modules/dashboard/controllers/Module.php', $content);
