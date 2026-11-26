-- ============================================================================
-- TechShift POS — fresh-install seed
-- Creates the default store, the admin account and default settings.
-- Default login: admin / admin
-- ============================================================================
BEGIN;

INSERT INTO stores (id, store_name, branch, address, phone, email, currency_symbol, tax_rate, description, status, owner_id, business_type)
VALUES (1, 'My Store', NULL, NULL, NULL, NULL, 'KSh', 0.00, NULL, 'active', 1, 'Retail')
ON CONFLICT (id) DO NOTHING;

INSERT INTO users (id, username, email, password, role, store_id, first_name, last_name, business_type, status, email_verified)
VALUES (1, 'admin', 'admin@pos.local',
        '$2y$10$oSzlxuFimSGTCdH1xOeWLuV6Aydye0WwR16floCYVW.V/XSLCZNXC',
        'admin', 1, 'System', 'Admin', 'Retail', 'active', 1)
ON CONFLICT (id) DO NOTHING;

INSERT INTO store_settings (setting_key, setting_value, setting_type) VALUES
('store_name',        'My Store',                          'text'),
('store_branch',      '',                                  'text'),
('store_address',     '',                                  'text'),
('store_phone',       '',                                  'text'),
('store_email',       '',                                  'text'),
('business_hours_open',  '08:00',                          'text'),
('business_hours_close', '20:00',                          'text'),
('business_days',     'Mon – Sun',                         'text'),
('currency_symbol',   'KSh',                               'text'),
('currency_code',     'KES',                               'text'),
('language',          'en',                                'text'),
('timezone',          'Africa/Nairobi',                    'text'),
('tax_rate',          '0',                                 'number'),
('tax_name',          'VAT',                               'text'),
('receipt_header',    'Thank you for your purchase',       'text'),
('receipt_footer',    'Thank you for your business',       'text'),
('receipt_show_logo',     '1',                             'text'),
('receipt_show_cashier',  '1',                             'text'),
('receipt_printer',       '',                              'text'),
('receipt_auto_print',    '1',                             'text'),
('receipt_paper_width',   '48',                            'text'),
('low_stock_threshold',   '10',                            'number'),
('enable_cash',       '1',                                 'text'),
('enable_mpesa',      '1',                                 'text'),
('enable_stripe',     '0',                                 'text'),
('min_order_amount',  '0',                                 'text'),
('max_discount_percent', '20',                             'text'),
('mpesa_provider',    'payhero',                           'text'),
('mpesa_ph_username', '',                                  'text'),
('mpesa_ph_password', '',                                  'text'),
('mpesa_ph_channel_id', '',                                'text'),
('mpesa_daraja_consumer_key',    '',                       'text'),
('mpesa_daraja_consumer_secret', '',                       'text'),
('mpesa_daraja_shortcode',       '',                       'text'),
('mpesa_daraja_passkey',         '',                       'text'),
('mpesa_daraja_type', 'CustomerPayBillOnline',             'text'),
('mpesa_callback_url', '',                                 'text'),
('stripe_publishable_key', '',                             'text'),
('stripe_secret_key',      '',                             'text'),
('stripe_webhook_secret',  '',                             'text'),
('stripe_mode',       'test',                              'text')
ON CONFLICT (setting_key) DO NOTHING;

INSERT INTO display_settings (store_id, setting_key, setting_value) VALUES
(1, 'display_mode', 'grid'),
(1, 'refresh_interval', '30'),
(1, 'show_prices', '1')
ON CONFLICT (store_id, setting_key) DO NOTHING;

SELECT setval(pg_get_serial_sequence('stores','id'),           (SELECT COALESCE(MAX(id),0)+1 FROM stores),           false);
SELECT setval(pg_get_serial_sequence('users','id'),            (SELECT COALESCE(MAX(id),0)+1 FROM users),            false);
SELECT setval(pg_get_serial_sequence('store_settings','id'),   (SELECT COALESCE(MAX(id),0)+1 FROM store_settings),   false);
SELECT setval(pg_get_serial_sequence('display_settings','id'), (SELECT COALESCE(MAX(id),0)+1 FROM display_settings), false);

COMMIT;
