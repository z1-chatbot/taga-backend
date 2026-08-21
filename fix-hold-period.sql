-- Fix earnings hold period to 0 hours for immediate availability
UPDATE delivery_settings 
SET value = '0' 
WHERE key = 'earnings_hold_period_hours';

-- Verify the change
SELECT * FROM delivery_settings WHERE key = 'earnings_hold_period_hours';
