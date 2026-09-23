ALTER TABLE users ADD COLUMN login_identifier VARCHAR(190) NULL AFTER name;

UPDATE users SET login_identifier=LOWER(email)
WHERE login_identifier IS NULL OR login_identifier='';

ALTER TABLE users
  MODIFY login_identifier VARCHAR(190) NOT NULL,
  MODIFY email VARCHAR(190) NULL;

CREATE UNIQUE INDEX uq_users_login_identifier ON users(login_identifier);
