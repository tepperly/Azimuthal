#!/usr/bin/env ruby
# $Id: mapcalcs.rb 146 2010-05-06 04:27:08Z tepperly $
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
ACODE = 'A'[0]
ZEROCODE = '0'[0]
SUBLONG = 2.0/24.0
SUBLAT = 1.0/24.0
EXTENDEDLONG = SUBLONG / 10.0
EXTENDEDLAT = SUBLAT / 10.0
MAIDENHEADREGEX = /^([A-Ra-r][A-Ra-r][0-9][0-9]([A-Xa-x][A-Xa-x]([0-9][0-9])?)?)$/

def maidenheadToLatLong(text)

  if text.length == 4 or text.length == 6 or text.length == 8
    text = text.upcase
    longitude = -180 + 20*(text[0]- ACODE) + 2*(text[2]- ZEROCODE)
    latitude = -90 + 10*(text[1] - ACODE) + 1*(text[3]- ZEROCODE)
    if text.length == 4
      longitude = longitude + 1
      latitude = latitude + 0.5
    else
      longitude = longitude + SUBLONG*(text[4] - ACODE)
      latitude = latitude + SUBLAT*(text[5] - ACODE)
      if text.length == 6
        longitude = longitude + 0.5 * SUBLONG
        latitude = latitude + 0.5 * SUBLAT
      else
        longitude = longitude + EXTENDEDLONG*((text[6] - ZEROCODE) + 0.5)
        latitude = latitude + EXTENDEDLAT*((text[7] - ZEROCODE) + 0.5)
      end
    end
  else
    longitude = nil
    latitude = nil
  end
  [ latitude, longitude ]
end

