USE smart;
INSERT INTO subscriptions (owner_id, plan_id, status, start_date, end_date) 
SELECT id, 3, 'active', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 10 YEAR) 
FROM users WHERE email='admin@admin.com';
