/* $Id: angledist_src.c 146 2010-05-06 04:27:08Z tepperly $ */

/*************************************************************************/
/* This file is part of the Azimuthal Map Creator.			 */
/* Copyright (C) 2010 Thomas G. W. Epperly NS6T				 */
/* 									 */
/* The Azimuthal Map Creator is free software: you can redistribute it	 */
/* and/or modify it under the terms of the GNU Affero General Public	 */
/* License as published by the Free Software Foundation, either version	 */
/* 3 of the License, or (at your option) any later version.		 */
/* 									 */
/* This program is distributed in the hope that it will be useful,	 */
/* but WITHOUT ANY WARRANTY; without even the implied warranty of	 */
/* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the	 */
/* GNU Affero General Public License for more details.			 */
/* 									 */
/* You should have received a copy of the GNU General Public License	 */
/* along with this program.  If not, see <http://www.gnu.org/licenses/>. */
/*************************************************************************/

#include "ruby.h"
#include <math.h>
#define SQR(x) ((x)*(x))
#define TWOPI (2*3.14159265358979323846)

static VALUE t_angleDist(VALUE self, 
                         VALUE f_lat, VALUE f_long,
                         VALUE s_lat, VALUE s_long)
{
  static const double EARTHRADIUS = 6371.01;
  const double delta = NUM2DBL(f_long) - NUM2DBL(s_long);
  const double cf_lat = NUM2DBL(f_lat);
  const double dac = cos(cf_lat);
  const double das = sin(cf_lat);
  const double cs_lat = NUM2DBL(s_lat);
  const double sac = cos(cs_lat);
  const double sas = sin(cs_lat);
  const double sd = sin(delta);
  const double cd = cos(delta);
  const double distance = EARTHRADIUS*
    atan2(sqrt(SQR(dac * sd) +
               SQR(sac * das - sas * dac * cd)),
          sas*das + sac * dac * cd);
  const double bearing = 
    fmod(atan2(sd * dac,sac * das - sas * dac * cd) + TWOPI,
         TWOPI);
  VALUE result = rb_ary_new2(2);
  rb_ary_store(result, 0, rb_float_new(distance));
  rb_ary_store(result, 1, rb_float_new(bearing));
  return result;
}

VALUE cAngleDist;


void Init_angledist() {
  cAngleDist = rb_define_class("AngleDist", rb_cObject);
  rb_define_method(cAngleDist, "calc", t_angleDist, 4);
}
