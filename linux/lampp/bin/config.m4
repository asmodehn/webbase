PHP_ARG_ENABLE(tclink, whether to enable tclink support,
Make sure that the comment is aligned:
[  --enable-tclink         Enable tclink support])

if test "$PHP_TCLINK" != "no"; then
  PHP_ADD_LIBRARY_WITH_PATH(crypto,/usr/local/lib,TCLINK_SHARED_LIBADD)
  PHP_ADD_LIBRARY_WITH_PATH(ssl,/usr/local/lib,TCLINK_SHARED_LIBADD)
  PHP_EXTENSION(tclink, $ext_shared)
fi
