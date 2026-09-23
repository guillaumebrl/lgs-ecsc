ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER id;
ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name;

UPDATE users SET last_name=name WHERE last_name='';

