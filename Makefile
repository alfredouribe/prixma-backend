serve:
	php -d upload_max_filesize=200M -d post_max_size=210M artisan serve --host=0.0.0.0 --port=8000

queue:
	php artisan queue:work --tries=3

.PHONY: serve queue
