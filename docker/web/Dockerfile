# Apache 2.4 in front of PHP-FPM, as on the webhosting, so every .htaccess in
# server/public is exercised locally. `php -S` ignores .htaccess entirely,
# which is how host-only 500s used to slip through on the sibling projects.
FROM httpd:2.4

RUN sed -i \
      -e 's/^#\(LoadModule rewrite_module .*\)/\1/' \
      -e 's/^#\(LoadModule proxy_module .*\)/\1/' \
      -e 's/^#\(LoadModule proxy_fcgi_module .*\)/\1/' \
      -e 's/^#\(LoadModule deflate_module .*\)/\1/' \
      -e 's/^#\(LoadModule expires_module .*\)/\1/' \
      -e 's/^#\(LoadModule headers_module .*\)/\1/' \
      conf/httpd.conf \
 && echo 'Include conf/extra/arche.conf' >> conf/httpd.conf

COPY docker/web/arche.conf conf/extra/arche.conf
