# CARES_CHECK_CONFIG ([DEFAULT-ACTION])
# ----------------------------------------------------------
#
# Checks for cares.
#
# This macro #defines HAVE_CARES_H if required header files are
# found, and sets @CARES_LDFLAGS@ and @CARES_CFLAGS@ to the necessary
# values.
#
# This macro is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.

AC_DEFUN([CARES_TRY_LINK],
[
found_cares=$1
AC_LINK_IFELSE([AC_LANG_PROGRAM([[
#include <ares.h>
]], [[
	ares_library_init(ARES_LIB_INIT_ALL);
]])],[found_cares="yes"],[])
])dnl

AC_DEFUN([CARES_CHECK_CONFIG],
[
	want_cares="no"
	AC_ARG_WITH([cares],[
If you want to use cares library:
AS_HELP_STRING([--with-cares@<:@=DIR@:>@], [use cares library @<:@default=no@:>@,])],
		[
			if test "x$withval" = "xyes"; then
				want_cares="yes"
			fi
		]
	)

	AC_ARG_WITH([cares-include],
		AS_HELP_STRING([--with-cares-include=DIR],
			[use cares include headers from given path.]
		),
		[
			CARES_CFLAGS="-I$withval"
			_cares_dir_set="yes"
		]
	)

	AC_ARG_WITH([cares-lib],
		AS_HELP_STRING([--with-cares-lib=DIR],
			[use cares libraries from given path.]
		),
		[
			CARES_LDFLAGS="-L$withval"
			_cares_dir_set="yes"
		]
	)

	if test "x$want_cares" != "xno"; then
		AC_MSG_CHECKING(for cares support)

		CARES_LIBS="-lcares"

		if test -n "$_cares_dir_set" -o -f /usr/include/ares.h; then
			found_cares="yes"
		elif test -f /usr/local/include/ares.h; then
			CARES_CFLAGS="-I/usr/local/include"
			CARES_LDFLAGS="-L/usr/local/lib"
			found_cares="yes"
		elif test -f /usr/pkg/include/ares.h; then
			CARES_CFLAGS="-I/usr/pkg/include"
			CARES_LDFLAGS="-L/usr/pkg/lib"
			found_cares="yes"
		else
			found_cares="no"
			AC_MSG_RESULT(no)
		fi

		if test "x$found_cares" = "xyes"; then
			am_save_CFLAGS="$CFLAGS"
			am_save_LDFLAGS="$LDFLAGS"
			am_save_LIBS="$LIBS"

			CFLAGS="$CFLAGS $CARES_CFLAGS"
			LDFLAGS="$LDFLAGS $CARES_LDFLAGS"
			LIBS="$LIBS $CARES_LIBS"

			CARES_TRY_LINK([no])

			CFLAGS="$am_save_CFLAGS"
			LDFLAGS="$am_save_LDFLAGS"
			LIBS="$am_save_LIBS"
		fi

		if test "x$found_cares" = "xyes"; then
			AC_DEFINE([HAVE_CARES], 1, [Define to 1 if you have the 'cares' library (-lcares)])
			AC_MSG_RESULT(yes)
		else
			CARES_CFLAGS=""
			CARES_LDFLAGS=""
			CARES_LIBS=""
		fi

		AC_SUBST(CARES_CFLAGS)
		AC_SUBST(CARES_LDFLAGS)
		AC_SUBST(CARES_LIBS)
	fi
])dnl
