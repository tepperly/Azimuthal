#!/usr/bin/env ruby
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
require 'pdf/writer'
require 'grid'

pdf = PDF::Writer.new(:paper => "LETTER")
points = Array.new(120)
0.upto(points.size-1) { |i|
  points[i] = [ 306 + 100 * Math::cos(2.0*Math::PI * i.to_f / points.size.to_f),
                396 + 100 * Math::sin(2.0*Math::PI * i.to_f / points.size.to_f) ]
}
points.each { |p|
  pdf.circle_at(p[0], p[1], 3).fill
}
s = Spline.new(points, :closed)
s.each { |p0, p1, p2, p3|
  pdf.curve(p0[0], p0[1], p1[0], p1[1], p2[0], p2[1], p3[0], p3[1]).stroke
}

points = Array.new(15)
points.each_index { |i|
  points[i] = [ 32 + i*10.0 + 10.0*rand, 32 + i * 15 + 12.0 * rand ]
}
points.each { |p|
  pdf.circle_at(p[0], p[1], 3).fill
}
s = Spline.new(points)
s.each { |p0, p1, p2, p3|
  pdf.curve(p0[0], p0[1], p1[0], p1[1], p2[0], p2[1], p3[0], p3[1]).stroke
}

pdf.save_as("gridtest.pdf")
