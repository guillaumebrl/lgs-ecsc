ALTER TABLE students MODIFY registration_number VARCHAR(50) NULL;

UPDATE students SET registration_number=NULL WHERE TRIM(registration_number)='';
