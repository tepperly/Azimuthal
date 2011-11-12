#!/usr/bin/env ruby
# $Id: anglecalc.rb 146 2010-05-06 04:27:08Z tepperly $
#
# This file is part of the Azimuthal Map Creator.
# Copyright (C) 2010 Thomas G. W. Epperly NS6T
#
# The Azimuthal Map Creator is free software: you can redistribute it
# and/or modify it under the terms of the GNU Affero General Public
# License as published by the Free Software Foundation, either version
# 3 of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU General Public License
#  along with this program.  If not, see <http://www.gnu.org/licenses/>.
#
begin
  require 'angledist'
rescue LoadError => le
  def sqr(x)
    x*x
  end

  class AngleDist
    EARTHRADIUS = 6371.01
    TWOPI = 2*Math::PI
    def calc(lat, long, reflat, reflong)
      delta = long - reflong
      dac = Math::cos(lat)
      das = Math::sin(lat)
      sac = Math::cos(reflat)
      sas = Math::sin(reflat)
      sd = Math::sin(delta)
      cd = Math::cos(delta)
      distance = EARTHRADIUS *
        Math::atan2(Math::sqrt(sqr(dac * sd) +
                               sqr(sac * das - sas * dac * cd)),
                    sas*das + sac*dac * cd)
      bearing = Math::atan2(sd*dac,
                            sac*das - sas * dac * cd)
      bearing = (bearing + TWOPI) % TWOPI
      return [distance, bearing]
    end
  end
end
