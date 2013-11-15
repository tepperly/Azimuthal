#!/usr/bin/env ruby
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
# Parse dates for CGI script queries
#
require 'time'

def parseDate(arg)
  if arg and arg.is_a?(String) and not arg.empty?
    now = Time.now
    str = arg.upcase
    if str == "TODAY"
      return Time.local(now.year, now.month, now.mday)
    elsif str == "YESTERDAY"
      return Time.local(now.year, now.month, now.mday) - 24*60*60
    elsif str == "LAST WEEK"
      return now - 7*24*60*60
    elsif str == "NOW"
      return now
    elsif str == "LAST MONTH"
      return now - 30*24*60*60
    else
      begin
        return Time.parse(arg)
      rescue
        return nil
      end
    end
  end
  nil
end
