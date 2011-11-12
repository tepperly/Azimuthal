#!/usr/bin/env ruby
# $Id: grid.rb 146 2010-05-06 04:27:08Z tepperly $
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

class Spline
  def initialize(points, type=:open)
    @points = points
    if type == :open
      openCurveSpline
    else
      closedCurveSpline
    end
  end

  def calculateCoeffs
    len = @points.size
    @Xc = Array.new(len)
    @Xd = Array.new(len)
    @Yc = Array.new(len)
    @Yd = Array.new(len)
    0.upto(len-2) { |i|
      @Xc[i] = 3.0*(@Xa[i+1] - @Xa[i]) - 2.0 * @Xb[i] - @Xb[i+1]
      @Yc[i] = 3.0*(@Ya[i+1] - @Ya[i]) - 2.0 * @Yb[i] - @Yb[i+1]
      @Xd[i] = 2.0*(@Xa[i] - @Xa[i+1]) + @Xb[i] + @Xb[i+1]
      @Yd[i] = 2.0*(@Ya[i] - @Ya[i+1]) + @Yb[i] + @Yb[i+1]
    }
    len = len - 1
      @Xc[len] = 3.0*(@Xa[0] - @Xa[len]) - 2.0 * @Xb[len] - @Xb[0]
      @Yc[len] = 3.0*(@Ya[0] - @Ya[len]) - 2.0 * @Yb[len] - @Yb[0]
      @Xd[len] = 2.0*(@Xa[len] - @Xa[0]) + @Xb[len] + @Xb[0]
      @Yd[len] = 2.0*(@Ya[len] - @Ya[0]) + @Yb[len] + @Yb[0]

  end

  def solveClosedCoeffs
    udiag = Array.new(@Xa.size)
    # Solve L * inter = rhs
    xinter = Array.new(@Xa.size)
    yinter = Array.new(@Xa.size)
    offdiag = Array.new(@Xa.size-1)
    lastrow = Array.new(@Xa.size-1)
    lastcol = Array.new(@Xa.size-1)
    lastrow[-1] = -1
    xinter[0] = 3.0*(@Xa[1] - @Xa[-1])
    yinter[0] = 3.0*(@Ya[1] - @Ya[-1])
    offdiag[0] = 0
    1.upto(offdiag.size - 1) { |i|
      offdiag[i] = 1.0/(4 - offdiag[i-1])
      udiag[i-1] = 1.0/offdiag[i]
      xinter[i] = (3.0*(@Xa[i+1] - @Xa[i-1]) - offdiag[i]*xinter[i-1])
      yinter[i] = (3.0*(@Ya[i+1] - @Ya[i-1]) - offdiag[i]*yinter[i-1])
      lastrow[i-1] = -lastrow[i-2]*offdiag[i]
    }
    udiag[-2] = 4 - offdiag[-1]
    lastrow[-1] = (1.0 - lastrow[-2])/udiag[-2]
    offdiag[-1] = lastrow[-1]
    offdiag[0] = -1
    xval = 0
    yval = 0
    lastrow.each_index { |i|
      xval = xval + lastrow[i] * xinter[i]
      yval = yval + lastrow[i] * yinter[i]
    }
    
    xinter[-1] = (3.0*(@Xa[0] - @Xa[-2]) - xval)
    yinter[-1] = (3.0*(@Ya[0] - @Ya[-2]) - yval)
    # Solve U * @Xb = inter
    lastcol[0] = 1
    1.upto(lastcol.size-1) { |i|
      lastcol[i] = -lastrow[i-1]
    }
    lastcol[-1] = 1.0 + lastcol[-1]
    adjustment = 0
    0.upto(lastrow.size-1) { |i|
      adjustment = adjustment + lastrow[i]*lastcol[i]
    }
    udiag[-1] = 4.0 - adjustment

    @Xb[-1] = xinter[-1] / udiag[-1]
    @Xb[-2] = (xinter[-2] - @Xb[-1]*lastcol[-1])/udiag[-2]
    @Yb[-1] = yinter[-1] / udiag[-1]
    @Yb[-2] = (yinter[-2] - @Yb[-1]*lastcol[-1])/udiag[-2]

    (udiag.size-3).downto(0) { |i|
      @Xb[i] = (xinter[i] - @Xb[i+1] - @Xb[-1]*lastcol[i])/udiag[i]
      @Yb[i] = (yinter[i] - @Yb[i+1] - @Yb[-1]*lastcol[i])/udiag[i]
    }
  end

  def closedCurveSpline
    values = Array.new(@points.size)
    @Xa = Array.new(@points.size)
    @Xb = Array.new(@points.size)
    @Ya = Array.new(@points.size)
    @Yb = Array.new(@points.size)
    @points.each_index { |i|
      p = @points[i]
      @Xa[i] = p[0]
      @Ya[i] = p[1]
    }
    solveClosedCoeffs
    calculateCoeffs
  end
  

  # Implement Natural cubic spline
  # based on algorithm in Numerical Analysis  by Burden & Faires 5th Edition
  # assuming all h's are 1
  # Assume x and y are both functions of a parameter t that varies from
  # 0 to n
  def openCurveSpline
    @Xa = Array.new(@points.size)
    @Ya = Array.new(@points.size)
    @Xb = Array.new(@points.size-1)
    @Yb = Array.new(@points.size-1)
    @Xc = Array.new(@points.size)
    @Yc = Array.new(@points.size)
    @Xd = Array.new(@points.size-1)
    @Yd = Array.new(@points.size-1)
    mu = Array.new(@points.size-1)
    xz = Array.new(@points.size)
    yz = Array.new(@points.size)
    l = Array.new(@points.size)
    @Xa.each_index { |i|
      p = @points[i]
      @Xa[i] = p[0]
      @Ya[i] = p[1]
    }
    mu[0] = 0
    xz[0] = 0
    yz[0] = 0
    l[0] = 1
    1.upto(@points.size-2) { |i|
      l[i] = 4.0 - mu[i-1]
      mu[i] = 1.0/l[i]
      xz[i] = (3.0*(@Xa[i+1] - 2.0*@Xa[i] + @Xa[i-1]) - xz[i-1])/l[i]
      yz[i] = (3.0*(@Ya[i+1] - 2.0*@Ya[i] + @Ya[i-1]) - yz[i-1])/l[i]
    }
    l[-1] = 1
    @Xc[-1] = 0
    @Yc[-1] = 0
    xz[-1] = 0
    yz[-1] = 0
    (@points.size-2).downto(0) { |i|
      @Xc[i] = xz[i] - mu[i] * @Xc[i+1]
      @Yc[i] = yz[i] - mu[i] * @Yc[i+1]
      @Xb[i] = (@Xa[i+1] - @Xa[i]) - (@Xc[i+1] + 2.0*@Xc[i])/3.0
      @Yb[i] = (@Ya[i+1] - @Ya[i]) - (@Yc[i+1] + 2.0*@Yc[i])/3.0
      @Xd[i] = (@Xc[i+1] - @Xc[i])/3.0
      @Yd[i] = (@Yc[i+1] - @Yc[i])/3.0
    }
    @Xa.pop
    @Xc.pop
    @Ya.pop
    @Yc.pop
  end

  def each 
    @Xc.each_index { |i|
      p0 = [ @Xa[i], @Ya[i] ]
      p1 = [ @Xb[i]/3.0 + p0[0], @Yb[i]/3.0 + p0[1] ]
      p2 = [ @Xc[i]/3.0 + 2.0*p1[0] - p0[0], @Yc[i]/3.0 + 2.0*p1[1] - p0[1] ]
      p3 = [ @Xd[i] + 3.0 * p2[0] - 3.0 * p1[0] + p0[0], @Yd[i] + 3.0 * p2[1] - 3.0 * p1[1] + p0[1]]
      yield(p0,p1,p2,p3)
    }
  end
end
