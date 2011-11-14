#!/usr/bin/env ruby
# $Id: azimuth.fcgi 162 2011-02-21 17:12:08Z tepperly $
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
ENV['RUBY_GEMS'] = "/home8/brightly/ruby/gems"
require 'rubygems'
require 'sqlite3'

require 'pdf/writer'
require 'color/rgb'
require 'fcgi'
require 'anglecalc'
require 'mapcalcs'
require 'cgierror'
require 'grid'

$ad = AngleDist.new
# require 'basicops'

TOLERANCE = 0.001
EARTHRADIUS=6371.01           # average value
TWOPI=Math::PI*2
PIOVERTWO=Math::PI/2
DEGTORAD=Math::PI/180.0
LOCATIONHELP ="""LOCATION 
Location must be either a Maidenhead grid square (e.g., IO91um),
a latitude, longitude, or the name of a prominent city. Latitude/longitude 
can be specified by a pair of signed floating point numbers separated by a
comma (e.g., 51.504, -0.268), or as space separated integers indicating
degrees, minutes, and seconds (e.g., 51 30 0N, 0 20 0W). In the third 
form, you can leave out the seconds or minutes (e.g., 51N, 0 20W or
51 30N, 0 20W). The absolute value of the latitude must be less than 
or equal to 90, and the absolute value of longitude must be less than
or equal to 180. The database of city names is far from complete. For 
US cities try city comma state, and for international cities try the city
name by itself.
"""

def min(x, y)
  x <= y ? x : y
end

def max(x, y)
  x >= y ? x : y
end

def sqr(value)
  return value * value
end

def sideFlip(p1, p2, radius)
  p1 and p2 and ((p2[1]-p1[1]).abs >= PIOVERTWO) and ((p2[1]-p1[1]).abs <= 3*Math::PI/2) and (p1[0] > 0.5*radius) and (p2[0] > 0.5*radius)
end


class Path 

  def initialize(num)
    @points = [ ]
    @closed = 1
    @pathnum = num
  end
  attr_reader :points, :closed, :pathnum
  attr_writer :closed

  def prepend(p)
    @points = p.points + @points
  end

  def addPoint(pt)
    @points << pt
  end
end

# def angleDist(lat, long, reflat, reflong)
#   delta = long - reflong
#   dac = Math::cos(lat)
#   das = Math::sin(lat)
#   sac = Math::cos(reflat)
#   sas = Math::sin(reflat)
#   sd = Math::sin(delta)
#   cd = Math::cos(delta)
#   distance = EARTHRADIUS *
#     Math::atan2(Math::sqrt(sqr(dac * sd) +
#                            sqr(sac * das - sas * dac * cd)),
#                 sas*das + sac*dac * cd)
#   bearing = Math::atan2(sd*dac,
#                         sac*das - sas * dac * cd)
#   bearing = (bearing + TWOPI) % TWOPI
#   return [distance, bearing]
# end

class FileReader
  PATHREGEX=/^Path ([0-9]+) +(([A-Z][a-z]+) +)?"([^"]*)"/
  SEGMENTREGEX=/^Open\s+([0-9]+)/
  POINTREGEX=/^ *([-+]?[0-9]+\.[0-9]*) *([-+]?[0-9]+\.[0-9]*)/
  def initialize(inf, radius, lat, long)
    @inf = inf
    @radius = radius
    @buffer = [ ]
    @current = nil
    @prev = nil
    @ptBuffer = [ ]
    @latitude = lat
    @longitude = long
  end

  def nextInt
    if @buffer.empty?
      buf = @inf.read(4)
      if buf
        num = buf.unpack("N")[0]
        if num[31] == 1
          num = -((num ^ 0xffff_ffff) + 1)
        end
        return num
      else
        return nil
      end
    else
      return @buffer.shift
    end
  end

  def nextSegment
    while (line = nextInt)
      if line == 0x7ffffff4
        @current = nil
        @prev = nil
        @ptBuffer = [ ]
        return nextInt
      end
    end
  end

  PATHTYPES = [ 'Unknown', 'Lake', 'Land', 'Island', 'Open' ]

  def nextPath
    while(line = nextInt)
      if (line == 0x7ffffff0) or (line == 0x7ffffff1) or (line == 0x7ffffff2) or (line == 0x7ffffff3)
        @current = nil
        @prev = nil
        @ptBuffer = [ ]
        return [nextInt, PATHTYPES[line & 0xf], ""]
      end
    end
    return nil
  end

  def interpolate(pt, prev, radius)
    if prev and not (prev[0] == pt[0])
      frac = (radius - pt[0])/(prev[0] - pt[0])
      pt = [ radius, frac*prev[1] + (1 - frac)*pt[1] ]
    end
    return pt
  end

  def translate(pt)
    pt[0] = 2*EARTHRADIUS - pt[0]
    pt[1] = (pt[1] + Math::PI) % TWOPI
    return pt
  end

  def outOfBounds?(point)
    point and (point[0] > @radius)
  end

  def nextPoint
    @prev = @current
    if @ptBuffer.empty?
      lat = nextInt
      if lat and ((lat & 0x7ffffff0) != 0x7ffffff0)
        long = nextInt
        @current = $ad.calc( lat.to_f/1000000.0, 
                             long.to_f/1000000.0, @latitude, @longitude )
        if sideFlip(@prev, @current, @radius)
          interp = interpolate(@prev, translate(@current), EARTHRADIUS)
          @ptBuffer << translate(interp) << @current
          @current = interp
        elsif ((not outOfBounds?(@current) and outOfBounds?(@prev)) or
               (outOfBounds?(@current) and not outOfBounds?(@prev)))
          @ptBuffer << @current
          @current = interpolate(@current, @prev, @radius)
        end
      else
        @current = nil
        @buffer << lat
      end
    else
      @current = @ptBuffer.shift
    end
    return @current
  end
end

def degree(rad, pos, neg)
  if rad >= 0
    tag = pos
  else
    tag = neg
    rad = -rad
  end
  deg = rad*180.0/Math::PI
  degi=deg.truncate
  min = (deg - degi)*60.0
  mini = min.truncate
  sec = ((min - mini)*60.0).truncate
  degi.to_s + "\260" + mini.to_s + "'" + sec.to_s + "\"" + tag
end
  

class AzimuthWriter
  GOLDENRATIO = 1.6180339887
  SEGMENTS=8
  def initialize(radius=EARTHRADIUS*Math::PI, # maximum distance in any direction
                 paper="LETTER", 
                 latitude=0.6579,#-Math::PI/2, #,  radians
                 longitude=-1.5,# , # -2.124in radians
                 blueBackground=true,
                 title="Azimuthal Projection",
                 bwonly = nil)
    @pdfwriter = PDF::Writer.new(:paper => paper)
    @pdfwriter.compressed = true
    @latitude = latitude
    @longitude = longitude
    @bwonly = bwonly
    width = min(@pdfwriter.margin_width, 0.85*@pdfwriter.page_width)
    height = min(@pdfwriter.margin_height, 0.85*@pdfwriter.page_height)
    @printRadius = 0.5*min(width, height) - 5
    @center = [ @pdfwriter.margin_x_middle,
                    ycenter ]
    @radius = radius
    if blueBackground
      if @bwonly
        @pdfwriter.fill_color!(Color::RGB::Gray80)
      else
        @pdfwriter.fill_color!(Color::RGB::SkyBlue)
      end
      @pdfwriter.circle_at(@center[0], @center[1], @printRadius).
        fill
    end
    @title = title
    setdocumentprops
  end

  def heading
    @pdfwriter.fill_color!(Color::RGB::Black)
    @pdfwriter.select_font("Times-Roman")
    @pdfwriter.text(@title, :font_size => titlefontsize, 
                    :justification => :center)
    if @radius < EARTHRADIUS*Math::PI
      @pdfwriter.text("Center: " + degree(@latitude, "N", "S") + " " +
                      degree(@longitude, "E", "W") +
                      sprintf("  Radius: %.0fkm", @radius), 
                      :font_size => subtitlesize,
                      :justification => :center)
    else
      @pdfwriter.text("Center: " + degree(@latitude, "N", "S") + " " +
                      degree(@longitude, "E", "W"), 
                      :font_size => subtitlesize,
                      :justification => :center)
    end
    @pdfwriter.text("Courtesy of Tom (NS6T)",
                    :font_size => complimentssize, 
                    :justification => :center)
  end

  def footer
    @pdfwriter.add_text_wrap(0, 18, @pdfwriter.page_width,
                             "Map from <c:alink uri=\"http://ns6t.net/\">http://ns6t.net/</c:alink>",
                             size=10,justification=:center)
  end

  def setdocumentprops
    @pdfwriter.info.title = @title
    @pdfwriter.info.author = "ns6t@arrl.net"
    @pdfwriter.info.subject = "Azimuthal map centered at " +
      degree(@latitude, "N", "S") + " " +
      degree(@longitude, "E", "W")
    @pdfwriter.info.keywords = "azimuthal map ham amateur radio NS6T"
    @pdfwriter.info.producer = 'azimuth.fcgi $Revision: 162 $ using PDF::Writer for Ruby'
  end

  def ycenter 
    return @pdfwriter.margin_y_middle
  end

  def outOfBounds?(point)
    point and (point[0] > @radius)
  end

  def toPSCoord(p)
    [ @center[0] + @printRadius * p[0] * Math::sin(p[1]) / @radius,
      @center[1] + @printRadius * p[0] * Math::cos(p[1]) / @radius ]
  end

  def arc(from, to)
    change = to[1] - from[1]
    if change >= Math::PI
      change = change - TWOPI
    elsif change <= -Math::PI
      change = change + TWOPI
    end
    segarc = change / SEGMENTS.to_f
    dtm = segarc / 3.0
    theta = from[1]
    p0 = toPSCoord([@radius, from[1]])
    @pdfwriter.line_to(p0[0], p0[1])
    c0 = @printRadius*Math::cos(from[1])
    d0 = -@printRadius*Math::sin(from[1])
    (1..SEGMENTS).each { |ii|
       theta = ii * segarc + from[1]
       p1 = toPSCoord([@radius, theta])
       c1 = @printRadius*Math::cos(theta)
       d1 = -@printRadius*Math::sin(theta)
       @pdfwriter.curve_to(p0[0] + (c0 * dtm),
                           p0[1] + (d0 * dtm),
                           p1[0] - (c1 * dtm),
                           p1[1] - (d1 * dtm),
                           p1[0], p1[1])
       p0 = p1
       c0 = c1
       d0 = d1
    }
  end

  def angleDiff(a1, a2)
    diff = a1 - a2
    if (diff >= 3*Math::PI/2)
      diff = diff - TWOPI
    elsif (diff <= -3*Math::PI/2)
      diff = diff + TWOPI
    end
    diff
  end

  def includesOpposite(points, path)
    feasible = nil
    if points.length > 2
      firstAngle = points[0][1]
      prevAngle = firstAngle
      positiveChanges = [ ]
      negativeChanges = [ ]
      points.each { |pt|
        curAngle = pt[1]
        diff = angleDiff(curAngle, prevAngle)
        if diff > 0
          positiveChanges << diff
        elsif diff < 0
          negativeChanges << diff
        end
        prevAngle = curAngle
        if not feasible
          feasible = (not outOfBounds?(pt))
        end
      }
      diff = angleDiff(firstAngle, prevAngle)
      if diff > 0
        positiveChanges << diff
      elsif diff < 0
        negativeChanges << diff
      end
      positiveChanges.sort! { |x, y| x <=> y }
      pSum = 0
      positiveChanges.each { |x| pSum = pSum + x }
      negativeChanges.sort! { |x, y| -1*(x <=> y) }
      nSum = 0
      negativeChanges.each { |x| nSum = nSum + x }
      sum = pSum + nSum
#       if sum.abs > 0.001
#         print "Sum = " + sum.to_s + " " + path.to_s + "\n"
#       end
      if sum > -Math::PI
        feasible = nil
      end
    end
    return feasible
  end

  def traceLines(inf)
    reader = FileReader.new(inf, @radius, @latitude, @longitude)
    while (segment = reader.nextSegment)
      prevpt = nil
      while pt = reader.nextPoint
        if pt[0] <= @radius
          psc = toPSCoord(pt)
          if prevpt
            @pdfwriter.line_to(psc[0], psc[1])
            if pt[0] >= @radius
              @pdfwriter.stroke
              prevpt = nil
            else
              prevpt = pt
            end
          else
            @pdfwriter.move_to(psc[0], psc[1])
            prevpt = pt
          end
        else
          prevpt = nil
        end
      end
      if prevpt
        @pdfwriter.stroke
      end
    end
  end

  def parseAndTrace(inf)
    reader = FileReader.new(inf, @radius, @latitude, @longitude)
    while (pathData = reader.nextPath)
      points = [ ]
      maxradius = 0
      while pt = reader.nextPoint
        points << pt
        if pt[0] > maxradius
          maxradius = pt[0]
        end
      end
      maxradius = min(maxradius, EARTHRADIUS*Math::PI)
      if "Lake" == pathData[1]
        if @bwonly
          @pdfwriter.fill_color!(Color::RGB::Gray80)
        else
          @pdfwriter.fill_color!(Color::RGB::SkyBlue)
        end
      else
        @pdfwriter.fill_color!(Color::RGB::White)
      end
      otherSide = includesOpposite(points, pathData)
      if not otherSide or points.length > 120
        if otherSide and @radius >= maxradius
          @pdfwriter.circle_at(@center[0], @center[1], @printRadius)
        end
        path = [ ]
        firstFeas = nil
        lastFeas = nil
        currPt = nil
        prevPt = nil
        flipped = nil
        points.each { |currPt|
          if sideFlip(prevPt, currPt, @radius)
            flipped = (not flipped)
          end
          if not (flipped or outOfBounds?(currPt))
            if not lastFeas
              firstFeas = currPt
              pt = toPSCoord(currPt)
              @pdfwriter.move_to(pt[0], pt[1])
            else
              if outOfBounds?(prevPt)
                arc(lastFeas, currPt)
              else
                pt = toPSCoord(currPt)
                @pdfwriter.line_to(pt[0], pt[1])
              end
            end
            lastFeas = currPt
          end
          prevPt = currPt
        }
        if firstFeas and lastFeas 
          if (firstFeas[0] == @radius) and (lastFeas[0] == @radius)
            arc(lastFeas, firstFeas)
          end
        end
        if not otherSide or path.empty?
          @pdfwriter.close_fill_stroke(:even_odd)
        end
        lastFeas = nil
        prevPt = nil
        if not path.empty?
          path.each { |currPt|
            if not outOfBounds?(currPt)
              if lastFeas
                if (lastFeas[0] == @radius) && (currPt[0] == @radius)
                  arc(lastFeas, currPt)
                else
                  pt = toPSCoord(currPt)
                  @pdfwriter.line_to(pt[0], pt[1])
                end
              else
                pt = toPSCoord(currPt)
                @pdfwriter.move_to(pt[0], pt[1])
              end
              lastFeas = currPt
            end
          }
          @pdfwriter.close_fill_stroke
        end
      end
    end
  end

  STDFONTSIZES= [ 0.5,
                  1, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 24, 28, 32, 36, 48, 72,
                  96, 144 ]
  def labelsize
    size = 10.0*(@printRadius / 260.0)
    STDFONTSIZES.each { |std|
      if std > size
        return std
      end
    }
    156
  end

  def titlefontsize
    size = 32.0*(@printRadius / 260.0)
    STDFONTSIZES.each { |std|
      if std > size
        return std
      end
    }
    156
  end

  def subtitlesize
    if @radius < EARTHRADIUS*Math::PI
      size = 18.0*(@printRadius / 260.0)
    else
      size = 24.0*(@printRadius / 260.0)
    end
    STDFONTSIZES.each { |std|
      if std > size
        return std
      end
    }
    156
  end

  def complimentssize
    size = 14.0*(@printRadius / 260.0)
    STDFONTSIZES.each { |std|
      if std > size
        return std
      end
    }
    156
  end

  def thickline
    @printRadius/260.0
  end

  def thinline
    @printRadius/1000.0
  end

  def thinnestline
    @printRadius/2500.0
  end

  def extraspace
    if @printRadius < 72
      return 2
    else
      return 5
    end
  end

  def narrowGapLong(lat, p1, p2)
    while ((p2 - p1) >= TOLERANCE)
      mid = 0.5*(p1 + p2)
      if ($ad.calc(lat*DEGTORAD, mid*DEGTORAD, @latitude, @longitude))[0] <= @radius
        p2 = mid
      else
        p1 = mid
      end
    end
    p2
  end

  def nextFeasibleLong(lat, long)
    prev = long
    while long < 360
      x = $ad.calc(lat*DEGTORAD, long*DEGTORAD, @latitude, @longitude)
      if (x[0] <= @radius)
        return narrowGapLong(lat, prev, long)
      end
      prev = long
      long = long + 1
    end
    long
  end

  def findNextLong(lat, long)
    startPolar = $ad.calc(lat*DEGTORAD, long *DEGTORAD,
                          @latitude, @longitude)
    startCoord = toPSCoord(startPolar)
    incr = min(5, 360 - long) # start with at most a 5 degree change
    nextLong = long + incr
    nextPolar = $ad.calc(lat*DEGTORAD, nextLong *DEGTORAD,
                         @latitude, @longitude)
    nextCoord = toPSCoord(nextPolar)
    while ((incr >= TOLERANCE) and ((distance(startCoord, nextCoord) >= 18) or
                               sideFlip(startPolar, nextPolar, @radius) or
                               nextPolar[0] > @radius))
      incr = 0.5*incr
      nextLong = long + incr
      nextPolar = $ad.calc(lat*DEGTORAD, nextLong *DEGTORAD,
                           @latitude, @longitude)
      nextCoord = toPSCoord(nextPolar)
    end
    (incr >= TOLERANCE) ? nextLong : nil
  end

  def drawLatitudeLine(latitude)
    ptCount = 0
    points = [ ]
    long = nextFeasibleLong(latitude, 0)
    closedCurve = (long  == 0)
    points << toPSCoord($ad.calc(latitude*DEGTORAD, long*DEGTORAD,
                                 @latitude, @longitude))
    ptCount = ptCount + 1
    while long < 360
      nextPoint = findNextLong(latitude, long)
      if nextPoint
        points << toPSCoord($ad.calc(latitude*DEGTORAD, nextPoint*DEGTORAD,
                                     @latitude, @longitude))
        ptCount = ptCount + 1
        long = nextPoint
      else
        drawSpline(points)
        points = [ ]
        closedCurve = nil
        long = nextFeasibleLong(latitude, long + TOLERANCE)
        if long < 360
          points << toPSCoord($ad.calc(latitude*DEGTORAD, long*DEGTORAD,
                                     @latitude, @longitude))
          ptCount = ptCount + 1
        end
      end
    end
    drawSpline(points, closedCurve ? :closed : :open)
  end

  def narrowGapLat(p1, p2, long)
    while ((p2 - p1) >= TOLERANCE)
      mid = 0.5*(p1 + p2)
      if ($ad.calc(mid*DEGTORAD, long*DEGTORAD, @latitude, @longitude))[0] <= @radius
        p2 = mid
      else
        p1 = mid
      end
    end
    p2
  end

  def nextFeasibleLat(lat, long)
    prev = lat
    while lat < 90
      x = $ad.calc(lat*DEGTORAD, long*DEGTORAD, @latitude, @longitude)
      if (x[0] <= @radius)
        return narrowGapLat(prev, lat, long)
      end
      prev = lat
      lat = lat + 1
    end
    lat
  end

  def drawSpline(pts, type = :open)
    if pts.length > 2
      s = Spline.new(pts, type)
      s.each { |p0, p1, p2, p3|
        @pdfwriter.curve(p0[0], p0[1], p1[0], p1[1], 
                         p2[0], p2[1], p3[0], p3[1]).stroke
      }
    end
  end

  def distance(p1, p2)
    Math::sqrt(sqr(p2[0].to_f - p1[0].to_f) + sqr(p2[1].to_f - p1[1].to_f))
  end
  
  def findNextLat(lat, long)
    startPolar = $ad.calc(lat*DEGTORAD, long *DEGTORAD,
                          @latitude, @longitude)
    startCoord = toPSCoord(startPolar)
    incr = min(5, 90 - lat) # start with at most a 5 degree change
    nextLat = lat + incr
    nextPolar = $ad.calc(nextLat*DEGTORAD, long *DEGTORAD,
                         @latitude, @longitude)
    nextCoord = toPSCoord(nextPolar)
    while ((incr >= TOLERANCE) and 
           ((distance(startCoord, nextCoord) >= 18) or
            sideFlip(startPolar, nextPolar, @radius) or
            nextPolar[0] > @radius))
      incr = 0.5*incr
      nextLat = lat + incr
      nextPolar = $ad.calc(nextLat*DEGTORAD, long *DEGTORAD,
                           @latitude, @longitude)
      nextCoord = toPSCoord(nextPolar)
    end
    (incr >= TOLERANCE) ? nextLat : nil
  end

  def drawLongitudeLine(long)
    points = [ ]
    lat = nextFeasibleLat(-90, long)
    points << toPSCoord($ad.calc(lat*DEGTORAD,long*DEGTORAD,
                                 @latitude, @longitude))
    while lat < 90
      nextPoint = findNextLat(lat, long)
      if nextPoint
        points << toPSCoord($ad.calc(nextPoint*DEGTORAD, long*DEGTORAD,
                                     @latitude, @longitude))
        lat = nextPoint
      else
        drawSpline(points)
        points = [ ]
        lat = nextFeasibleLat(lat+TOLERANCE, long) # find next feasible point
        if lat < 90
          points << toPSCoord($ad.calc(lat*DEGTORAD, long*DEGTORAD,
                                       @latitude, @longitude))
        end
      end
    end
    drawSpline(points)
  end

  def gridsquarelabels
    @pdfwriter.save_state
    style = PDF::Writer::StrokeStyle.new(thinnestline)
    @pdfwriter.stroke_style(style)
    if @bwonly
      @pdfwriter.stroke_color!(Color::RGB::Gray40)
      @pdfwriter.fill_color!(Color::RGB::Gray40)
    else
      @pdfwriter.stroke_color!(Color::RGB::Red)
      @pdfwriter.fill_color!(Color::RGB::Red)
    end
    @pdfwriter.text_render_style!(1)
    stdsize = 600.0*(@printRadius / max(@radius,10))
    'A'.upto('R') { |lat|
      size = case lat
               when 'A' then 0.25*stdsize
               when 'B' then 0.5*stdsize
               when 'Q' then 0.5*stdsize
               when 'R' then 0.25*stdsize
               else stdsize
             end
      yadjust = -0.5*@pdfwriter.font_height(size)
      'A'.upto('R') { |long|
        coord = maidenheadToLatLong(long + lat + "55")
        polar = $ad.calc(coord[0]*DEGTORAD, coord[1]*DEGTORAD,
                                 @latitude, @longitude)
        if polar[0] <= @radius
          psc = toPSCoord(polar)
          text = long+lat
          xadjust = -0.5*@pdfwriter.text_width(text, size)
          @pdfwriter.add_text(psc[0]+xadjust, psc[1]+yadjust, text, size)
        end
      }
    }
    @pdfwriter.restore_state
  end

  def latlonggrid
    @pdfwriter.save_state
    style = PDF::Writer::StrokeStyle.new(thinnestline)
    @pdfwriter.stroke_style(style)
    if @bwonly
      @pdfwriter.stroke_color!(Color::RGB::Gray60)
      @pdfwriter.fill_color!(Color::RGB::Gray60)
    else
      @pdfwriter.stroke_color!(Color::RGB::Red)
      @pdfwriter.fill_color!(Color::RGB::Red)
    end
    17.times { |i|
      drawLatitudeLine(-80 + i*10)
    }
    18.times { |i|
      drawLongitudeLine(i*20)
    }
    @pdfwriter.restore_state
  end

  def clearOutsideMap
    @pdfwriter.move_to(0,0).line_to(0,@pdfwriter.page_height).
      line_to(@pdfwriter.page_width, @pdfwriter.page_height).
      line_to(@pdfwriter.page_width,0).close
    @pdfwriter.circle_at(@center[0], @center[1], @printRadius)
    @pdfwriter.fill_color!(Color::RGB::White)
    @pdfwriter.fill(:even_odd)
  end


  def frame
    @pdfwriter.save_state
    style = PDF::Writer::StrokeStyle.new(thickline)
    @pdfwriter.stroke_style(style)
    @pdfwriter.fill_color!(Color::RGB::Black)
      @pdfwriter.translate_axis(@center[0], @center[1])
    outer =  @printRadius+extraspace
    thin = PDF::Writer::StrokeStyle.new(thinline)
    @pdfwriter.stroke_style(thin)
    360.times { |i|
      r = i*DEGTORAD
      s = Math::sin(r)
      c = Math::cos(r)
      if ((i % 10) == 0)
        label = ((90 - i) % 360).to_s + "\260"
        w = @pdfwriter.text_width(label, labelsize)
        x = (outer+0.5*thickline) * c
        y = (outer+0.5*thickline) * s
        if i == 0 or i == 180
          y = y - 0.5*labelsize
        elsif i > 180
          y = y - labelsize
        end
        if i == 90 or i == 270
          x = x - 0.5*w
        elsif i > 90 and i < 270
          x = x - 0.9*w
        end
        @pdfwriter.add_text(x, y, label, labelsize)
        @pdfwriter.stroke_style(style)
      end
      @pdfwriter.move_to(@printRadius*c, @printRadius*s)
      @pdfwriter.line_to(outer*c, outer*s).stroke
      if ((i % 10) == 0)
        @pdfwriter.stroke_style(thin)
      end
    }
    @pdfwriter.stroke_style(thin)
    @pdfwriter.stroke_color!(Color::RGB::Gray50)
    @pdfwriter.circle_at(0, 0, 0.25*@printRadius).close_stroke
    @pdfwriter.circle_at(0, 0, 0.5*@printRadius).close_stroke
    @pdfwriter.circle_at(0, 0, 0.75*@printRadius).close_stroke
    72.times { |i|
      r = i*5*DEGTORAD
      s = Math::sin(r)
      c = Math::cos(r)
      if (i % 6) == 0
        @pdfwriter.move_to(0,0)
      elsif (i % 2) == 0
        @pdfwriter.move_to(0.25*@printRadius*c, 0.25*@printRadius*s)
      else
        @pdfwriter.move_to(0.5*@printRadius*c, 0.5*@printRadius*s)
      end
      @pdfwriter.line_to(@printRadius*c,@printRadius*s).close_stroke
    }
    @pdfwriter.stroke_color!(Color::RGB::Black)
    @pdfwriter.circle_at(0, 0, @printRadius).close_stroke
    @pdfwriter.circle_at(0, 0, @printRadius+extraspace).close_stroke
      
    @pdfwriter.restore_state
  end
    

  def traceFile(inf)
    @pdfwriter.fill_color!(Color::RGB::White)
    style = PDF::Writer::StrokeStyle.new(thinline)
    style.join = :round
    @pdfwriter.stroke_style(style)
    parseAndTrace(inf)
  end

  def dump(out)
    out.write(@pdfwriter.render)
  end
  
  def render
    return @pdfwriter.render
  end

  CASTATES = [
              [ 'Alberta', 54.8, -115.0 ],
              [ 'British Columbia', 55.0, -125.0 ],
              [ 'Manitoba', 54.5, -97.5 ],
              [ 'New Brunswick', 46.75, -66.75],
              [ 'Newfoundland & Labrador', 53.0 + 30.0/60.0, -61.75],
              [ 'Newfoundland', 48.45, -57.0 ],
              [ 'Northwest Territories', 63.33, -120.12],
              [ 'Nova Scotia', 44.0, -65.43],
              [ 'Nunavut', 64.0, -97.8],
              [ 'Ontario', 51.0, -87.0],
              [ "Qu\351bec", 52.0, -71.6],
              [ 'Saskatchewan', 53.5, -106.2 ],
              [ 'Yukon Territory', 63.0, -136.0 ],
              ]

  USSTATES = [ 
              [ 'Alabama', 32.0+45.0/60.0, -(86.0+56.0/60.0) ],
              [ 'Alaska', 64.1, -153.0 ],
              [ 'Arizona', 34.0 + 21.0/60.0, -(111.0 + 47.0/60.0) ],
              [ 'Arkansas', 34.0+26.0/60.0, -(92.0+26.0/60.0) ],
              [ 'California', 35.8, -119.0 ],
              [ 'Colorado', 38.0 + 47.0/60.0, -(105.0 + 39.0/60.0) ],
              [ 'Connecticut', 41.0 + 15.0/60.0, -73.0 ], 
              [ 'Delaware', 38.0 + 28.0/60.0, -(75.0 + 34.0/60.0) ],
              [ 'Florida', 28.0 + 10.0/60.0, -(81.0 + 54.0/60.0) ],
              [ 'Georgia', 31.0+58.0/60.0, -(83.0 + 23.0/60.0) ],
              [ 'Hawaii', 19.0 + 30.0/60.0, -(155.0 + 15.0/60.0) ],
              [ 'Idaho', 43.2, -114.5 ],
              [ 'Illinois', 40.0 + 21.0/60.0, -(89.0 + 31.0/60.0) ],
              [ 'Indiana', 39.0 + 28.0/60.0, -(86.0 + 24.0/60.0) ],
              [ 'Iowa', 41.0 + 47.0/60.0, -(93.0 + 35.0/60.0) ],
              [ 'Kansas', 38.0 + 21.0/60.0, -(98.0 + 42.0/60.0) ],
              [ 'Kentucky', 37.0, -(85.0 + 40.0/60.0) ],
              [ 'Louisiana', 30.5, -91.8], 
              [ 'Maine', 45.0 + 4.0/60.0, -(69.0 + 19.0/60.0)] ,
              [ 'Massachusetts', 42.05, -(71.0 + 54.0/60.0) ],
              [ 'Michigan', 42.0 + 53.0/60.0, -(84.0 + 48.0/60.0) ],
              [ 'Minnesota', 46.0 + 12.0/60.0, -(94.0 + 35.0/60.0) ],
              [ 'Mississippi', 32.0+31.0/60.0, -(89.0+50.0/60.0) ],
              [ 'Missouri', 37.5, -(92.0 + 35.0/60.0) ],
              [ 'Montana', 46.75, -110.0 ],
              [ 'Nebraska', 41.0 + 15.0/60.0, -100.0 ],
              [ 'Nevada', 39.0 + 29.0/60.0, -117.0 ],
              [ 'New Hampshire', 43.0 + 7.0/60.0, -(71.0 + 51.0/60.0)],
              [ 'New Jersey', 39.65, -(74.0 + 35.0/60.0) ],
              [ 'New Mexico', 34.0 + 4.0/60.0, -(106.0 + 13.0/60.0) ],
              [ 'New York', 42.5 + 55.0/60.0, -(76.0 + 6.0/60.0) ],
              [ 'North Carolina', 35.0+3.0/60.0, -(78.0 + 58.0/60.0) ],
              [ 'North Dakota', 47.0 + 14.0/60.0, -(100.0 + 33.0/60.0) ],
              [ 'Ohio', 40.5, -(83.0 + 2.0/60.0) ],
              [ 'Oklahoma', 35.0+10.0/60.0, -(97.0+9.0/60.0) ],
              [ 'Oregon', 43.6, -120.75 ],
              [ 'Pennsylvania', 40.0 + 51.0/60.0, -(77.0 + 47.0/60.0) ],
              [ 'Rhode Island', 41.0 + 25.0/60.0, -(71.0 + 47.0/60.0) ],
              [ 'South Carolina', 33.0+16.0/60.0, -(80.0 + 55.0/60.0) ],
              [ 'South Dakota', 44.0 + 4.0/60.0, -(100.0 + 29.0/60.0) ],
              [ 'Tennessee', 35.0 + 17.0/60.0, -(86.0 + 49.0/60.0) ],
              [ 'Texas', 31.0+26.0/60.0, -(98.0+33.0/60.0) ],
              [ 'Utah', 39.0 + 15.0/60.0, -111.75 ],
              [ 'Vermont', 44.0 + 1.0/60.0, -(72.0 + 51.0/60.0) ],
              [ 'Virginia', 37.0+7.0/60.0, -(78.0 + 10.0/60.0) ],
              [ 'Washington', 46.5, -120.54 ],
              [ 'West Virginia', 39.0, -(80.0 + 53.0/60.0) ],
              [ 'Wisconsin', 44.0 + 19.0/60.0, -90.0 ],
              [ 'Wyoming', 42.5, -107.5 ]
             ]

  COUNTRIES = [
               [ 'Antarctica', -82.5, 90, 5],
               [ "Cote d'Ivoire", 6.49, -5.17, 1 ],
               [ 'Afghanistan', 34.28, 69.11, 1 ],
               [ 'Albania', 41.18, 19.49, 1 ],
               [ 'Algeria', 27.2, 3.08, 2 ],
               [ 'American  Samoa', -14.16, -170.43, 1 ],
               [ 'Andorra', 42.31, 1.32, 1 ],
               [ 'Angola', -12.5, 18.15, 1.5 ],
               [ 'Antigua and Barbuda', 17.2, -61.48, 1 ],
               [ 'Argentina', -36.3, -64.0, 1.5 ],
               [ 'Armenia', 40.1, 44.31, 1 ],
               [ 'Aruba', 12.32, -70.02, 1 ],
               [ 'Australia', -25, 134, 3.75 ],
               [ 'Austria', 47.6, 14.41, 1 ],
               [ 'Azerbaijan', 40.29, 49.56, 1 ],
               [ 'Bahamas', 25.05, -77.2, 1 ],
               [ 'Bahrain', 26.1, 50.3, 1 ],
               [ 'Bangladesh', 24, 90.0, 1 ],
               [ 'Barbados', 13.05, -59.3, 1 ],
               [ 'Belarus', 53.52, 27.3, 1 ],
               [ 'Belgium', 50.51, 4.21, 1 ],
               [ 'Belize', 17.18, -88.3, 1 ],
               [ 'Bhutan', 27.4, 90.5, 1 ],
               [ 'Bolivia', -16.2, -65.1, 1.7 ],
               [ 'Bosnia and Herzegovina', 43.52, 18.26, 1 ],
               [ 'Botswana', -22.45, 23.57, 1 ],
               [ 'Brazil', -11.5, -52, 4.5 ],
               [ 'British Virgin Islands', 18.27, -64.37, 1 ],
               [ 'Brunei Darussalam', 4.52, 115.0, 1 ],
               [ 'Bulgaria', 42.45, 24.7, 1 ],
               [ 'Burkina Faso', 12.15, -1.3, 1 ],
               [ 'Burundi', -3.16, 29.18, 1 ],
               [ 'Cambodia', 12.5, 105.0, 1 ],
               [ 'Cameroon', 4.8, 12.6, 1 ],
               [ 'Canada', 56, -106, 3 ],
               [ 'Cape Verde', 15.02, -23.34, 1 ],
               [ 'Cayman Islands', 19.2, -81.24, 1 ],
               [ 'Central African Republic', 6, 19.35, 1 ],
               [ 'Chad', 14, 18, 1.5 ],
               [ 'Chile', -23.5, -68.9, 1 ],
               [ 'China', 35.75, 104.0, 4.5 ],
               [ 'Colombia', 4.34, -74.0, 1.5 ],
               [ 'Comros', -11.4, 43.16, 1 ],
               [ 'Congo', -3, 15.12, 1 ],
               [ 'D. R. Congo', -3.120, 22.12, 1.5 ],
               [ 'Costa Rica', 9.55, -84.02, 1 ],
               [ 'Croatia', 45.5, 15.58, 1 ],
               [ 'Cuba', 23.08, -82.22, 1 ],
               [ 'Cyprus', 35.1, 33.25, 1 ],
               [ 'Czech Republic', 49.4, 15, 1 ],
               [ 'Denmark', 55.41, 12.34, 1 ],
               [ 'Djibouti', 11.08, 42.2, 1 ],
               [ 'Dominica Republic', 18.3, -69.59, 1 ],
               [ 'Dominica', 15.2, -61.24, 1 ],
               [ 'East Timor', -8.29, 125.34, 1 ],
               [ 'Ecuador', -1.15, -78.35, 1.5 ],
               [ 'Egypt', 26, 30, 2 ],
               [ 'El Salvador', 13.4, -89.1, 1 ],
               [ 'Equatorial Guinea', 3.45, 8.5, 1 ],
               [ 'Eritrea', 15.19, 38.55, 1 ],
               [ 'Estonia', 59.22, 24.48, 1 ],
               [ 'Ethiopia', 9.02, 38.42, 2 ],
               [ 'Falkland Islands', -51.4, -59.51, 1 ],
               [ 'Faroe Islands', 62.05, -6.56, 1 ],
               [ 'Fiji', -18.06, 178.3, 1 ],
               [ 'Finland', 64.15, 25.53, 1 ],
               [ 'France', 46.0, 3.0, 1.5 ],
               [ 'French Guiana', 5.05, -52.18, 1 ],
               [ 'French Polynesia', -17.32, -149.34, 1 ],
               [ 'Gabon', 0.25, 9.26, 1 ],
               [ 'Gambia', 13.28, -16.4, 1 ],
               [ 'Georgia', 41.43, 44.5, 1 ],
               [ 'Germany', 51, 10.13, 1.3 ],
               [ 'Ghana', 5.35, -0.06, 1 ],
               [ 'Greece', 39.6, 21.54, 1 ],
               [ 'Greenland', 72.5, -40.0, 2 ],
               [ 'Guadeloupe', 16.0, -61.44, 1 ],
               [ 'Guatemala', 14.4, -90.22, 1 ],
               [ 'Guernsey', 49.26, -2.33, 1 ],
               [ 'Guinea', 9.29, -13.49, 1 ],
               [ 'Guinea-Bissau', 11.45, -15.45, 1 ],
               [ 'Guyana', 5.5, -59, 1 ],
               [ 'Haiti', 18.4, -72.2, 1 ],
               [ 'Honduras', 14.5, -87.14, 1 ],
               [ 'Hungary', 47, 19.4, 1 ],
               [ 'Iceland', 64.75, -18.25, 1 ],
               [ 'India', 21.5, 79, 3 ],
               [ 'Indonesia', -6.09, 106.49, 1 ],
               [ 'Iran', 32.44, 54.3, 2.5 ],
               [ 'Iraq', 33.2, 42.3, 1.5 ],
               [ 'Ireland', 53.0, -7.75, 1 ],
               [ 'Israel', 31.71, 35.1, 1 ],
               [ 'Italy', 42.5, 12.7, 1 ],
               [ 'Jamaica', 18.0, -76.5, 1 ],
               [ 'Japan', 36, 138, 1 ],
               [ 'Jordan', 31.57, 35.52, 1 ],
               [ 'Kazakhstan', 51.1, 71.3, 1 ],
               [ 'Kenya', 0, 37, 1 ],
               [ 'Kiribati', 1.3, 173.0, 1 ],
               [ 'Korea', 37.5, 127.5, 1 ],
               [ 'Kuwait', 29.3, 48.0, 1 ],
               [ 'Kyrgyzstan', 42.54, 74.46, 1 ],
               [ 'Laos', 20, 102.5, 1],
               [ 'Latvia', 56.53, 24.08, 1 ],
               [ 'Lebanon', 33.53, 35.31, 1 ],
               [ 'Lesotho', -29.18, 27.3, 1 ],
               [ 'Libya', 27.2, 17, 2 ],
               [ 'Liberia', 6.18, -10.47, 1 ],
               [ 'Liechtenstein', 47.08, 9.31, 1 ],
               [ 'Lithuania', 54.38, 25.19, 1 ],
               [ 'Luxembourg', 49.37, 6.09, 1 ],
               [ 'Macao, China', 22.12, 113.33, 1 ],
               [ 'Madagascar', -18.55, 47.31, 1 ],
               [ 'Malawi', -14.0, 33.48, 1 ],
               [ 'Malaysia', 3.09, 101.41, 1 ],
               [ 'Maldives', 4.0, 73.28, 1 ],
               [ 'Mali', 18.2, -1.3, 2 ],
               [ 'Malta', 35.54, 14.31, 1 ],
               [ 'Martinique', 14.36, -61.02, 1 ],
               [ 'Mauritania', -20.1, 57.3, 1 ],
               [ 'Mayotte', -12.48, 45.14, 1 ],
               [ 'Mexico', 23.5, -102.5, 2 ],
               [ 'Micronesia', 6.55, 158.09, 1 ],
               [ 'Moldova', 47.02, 28.5, 1 ],
               [ 'Mozambique', -14.58, 38, 1 ],
               [ 'Myanmar', 21, 96, 1 ],
               [ 'Namibia', -22.35, 17.04, 1 ],
               [ 'Nepal', 27.45, 85.2, 1 ],
               [ 'Netherlands Antilles', 12.05, -69.0, 1 ],
               [ 'Netherlands', 52.23, 4.54, 1 ],
               [ 'New Caledonia', -22.17, 166.3, 1 ],
               [ 'New Zealand', -41.19, 174.46, 1 ],
               [ 'Nicaragua', 12.06, -86.2, 1 ],
               [ 'Niger', 16.27, 10.06, 2 ],
               [ 'Nigeria', 9.05, 7.32, 2 ],
               [ 'Norfolk Island', -29.032097, 167.952381, 1 ], # Wikipedia
               [ 'Northern Mariana Islands', 15.12, 145.45, 1 ],
               [ 'Norway', 61, 9, 1 ],
               [ 'Oman', 23.37, 58.36, 1 ],
               [ 'Pakistan', 33.4, 73.1, 1 ],
               [ 'Palau', 7.2, 134.28, 1 ],
               [ 'Panama', 9.0, -79.25, 1 ],
               [ 'Papua New Guinea', -9.24, 147.08, 1 ],
               [ 'Paraguay', -23.5, -58.3, 1 ],
               [ 'Peru', -8.0, -76.0, 1.5 ],
               [ 'Philippines', 16.4, 121.5, 1 ],
               [ 'Poland', 52.0, 19.5, 1.2 ],
               [ 'Portugal', 39.5, -8.5, 1 ],
               [ 'Puerto Rico', 18.28, -66.07, 1 ],
               [ 'Qatar', 25.15, 51.35, 1 ],
               [ 'Rawanda', -1.59, 30.04, 1 ],
               [ 'Romania', 45.5, 25, 1 ],
               [ 'Russian Federation', 55.45, 37.35, 1 ],
               [ 'Saint Kitts and Nevis', 17.17, -62.43, 1 ],
               [ 'Saint Lucia', 14.02, -60.58, 1 ],
               [ 'Saint Pierre and Miquelon', 46.46, -56.12, 1 ],
               [ 'Samoa', -13.5, -171.5, 1 ],
               [ 'San Marino', 43.55, 12.3, 1 ],
               [ 'Sao Tome and Principe', 0.1, 6.39, 1 ],
               [ 'Saudi Arabia', 22.41, 46, 2 ],
               [ 'Senegal', 14.34, -17.29, 1 ],
               [ 'Sierra Leone', 8.3, -13.17, 1 ],
               [ 'Slovakia', 48.1, 17.07, 1 ],
               [ 'Slovenia', 46.04, 14.33, 1 ],
               [ 'Solomon Islands', -9.27, 159.57, 1 ],
               [ 'Somalia', 2.02, 45.25, 1 ],
               [ 'South Africa', -31, 23.12, 1 ],
               [ 'Spain', 40.25, -3.45, 1.5 ],
               [ 'Sudan', 14, 30, 2 ],
               [ 'Suriname', 5, -55.5, 1 ],
               [ 'Swaziland', -26.18, 31.06, 1 ],
               [ 'Sweden', 59.5, 14.9, 1 ],
               [ 'Switzerland', 46.57, 7.28, 1 ],
               [ 'Syrian Arab Republic', 33.3, 36.18, 1 ],
               [ 'Tajikistan', 38.33, 68.48, 1 ],
               [ 'Tanzania', -7.08, 35.45, 1.2 ],
               [ 'Thailand', 15.75, 101.5, 1 ],
               [ 'Togo', 6.09, 1.2, 1 ],
               [ 'Tonga', -21.1, -174.0, 1 ],
               [ 'Tunisia', 34.5, 10.11, 1 ],
               [ 'Turkey', 39, 35, 1.3 ],
               [ 'Turkmenistan', 38.0, 57.5, 1 ],
               [ 'Tuvalu', -8.31, 179.13, 1 ],
               [ 'Uganda', 0.2, 32.3, 1 ],
               [ 'Ukraine', 50.3, 30.28, 1 ],
               [ 'United Arab Emirates', 24.28, 54.22, 1 ],
               [ 'United Kingdom', 52.0, -1.0, 1 ],
               [ 'United States of America', 39.91, -98.5, 3.5 ],
               [ 'Uruguay', -34, -56.11, 1 ],
               [ 'Uzbekistan', 41.2, 69.1, 1 ],
               [ 'Vanuatu', -17.45, 168.18, 1 ],
               [ 'Venezuela', 7.3, -66, 1.2 ],
               [ 'Viet Nam', 21.5, 105.0, 1 ],
               [ 'Yugoslavia', 44.5, 20.37, 1 ],
               [ 'Zambia', -14.2, 28.16, 1 ],
               [ 'Zimbabwe', -19, 30, 1 ],
             ]

  def labelCountries
    @pdfwriter.fill_color!(Color::RGB::Gray)
    @pdfwriter.select_font("Helvetica")
    fontsize  =80.0*@printRadius/@radius
    (COUNTRIES).each { |state|
      polar = $ad.calc(state[1]*DEGTORAD, state[2]*DEGTORAD,
                       @latitude, @longitude)
      if polar[0] < @radius
        ps = toPSCoord(polar)
        @pdfwriter.add_text(ps[0]-0.5*@pdfwriter.text_width(state[0], 
                                                            fontsize*state[3]),
                            ps[1], state[0],fontsize*state[3])
      end
    }
    clearOutsideMap
  end

  def isClear(list, x, y, bb)
    list.each { |entry|
      if (not ((bb[0][0] >= entry[2][1][0]) or
               (bb[1][0] <= entry[2][0][0]) or
               (bb[1][1] <= entry[2][0][1]) or
               (bb[0][1] >= entry[2][1][1])))
        return nil
      end
    }
    true
  end

  def labelSites(inf, fontsize, dotsize, extra_space, obscured, cutoff)
    comment = /^\s*\#/
    while (line = inf.gets)
      if (not comment.match(line))
        stateInfo = line.split("|")
        if (stateInfo[2].to_i >= cutoff)
          polar = $ad.calc(stateInfo[3].to_f * DEGTORAD,
                           stateInfo[4].to_f * DEGTORAD,
                           @latitude, @longitude)
          if polar[0] < @radius
            ps = toPSCoord(polar)
            textRadius = 0.55*@pdfwriter.text_width(stateInfo[0], fontsize)
            bb = [[ps[0] - 0.83333333*textRadius - extra_space,
                   ps[1] - dotsize - extra_space],
                  [ps[0] + 0.83333333*textRadius + extra_space,
                   ps[1] + 1.25*dotsize + fontsize + extra_space]]
            if isClear(obscured, ps[0], ps[1], bb)
              obscured.push([ps[0], ps[1], bb])
              @pdfwriter.circle_at(ps[0], ps[1], dotsize).fill
              @pdfwriter.add_text(ps[0] - 0.8333333*textRadius, 
                                  ps[1] + 1.25*dotsize,
                                  stateInfo[0], fontsize)
            end
          end
        end
      end
    end
  end
    
  def calcCutoff
    if @radius >= 20000
      return 500000
    elsif @radius >= 10000
      return 250000
    elsif @radius >= 5000
      return 5000
    elsif @radius >= 2000
      return 1000
    end
    return 0
  end

  def labelCities
    extra_space = 1
    @pdfwriter.fill_color!(Color::RGB::Black)
    @pdfwriter.select_font("Helvetica")
    fontsize = min(45.0*@printRadius/@radius,9)
    dotsize = min(4.0*@printRadius/@radius,1.5)
    obscured = [ ]
    cutoff = calcCutoff
    File.open("us_cities.txt") { |inf|
      labelSites(inf, fontsize, dotsize, extra_space, obscured, cutoff)
    }
    File.open("world_cities.txt") { |inf|
      labelSites(inf, fontsize, dotsize, extra_space, obscured, cutoff)
    }
    clearOutsideMap
  end

  def labelStates
    @pdfwriter.fill_color!(Color::RGB::Black)
    @pdfwriter.select_font("Helvetica")
    fontsize  =80.0*@printRadius/@radius
    (USSTATES+CASTATES).each { |state|
      polar = $ad.calc(state[1]*DEGTORAD, state[2]*DEGTORAD,
                       @latitude, @longitude)
      if polar[0] < @radius
        ps = toPSCoord(polar)
        @pdfwriter.add_text(ps[0]-0.5*@pdfwriter.text_width(state[0], fontsize),
                            ps[1], state[0],fontsize)
      end
    }
    clearOutsideMap
  end
end

def titleOkay(title)
  if title and title.length >= 0 and title.length <= 30
    title.each_byte { |b|
      if ((b >= 0 and b < 32) or (b == 127))
        return false
      end
    }
    return true
  end
  false
end

def searchFile(fin, city, region, limit)
  city = city.upcase
  if region
    region = region.upcase
  end
  while (limit > 0) and (line = fin.gets)
    fields = line.split("|")
    if ((city == fields[0].upcase) and 
        ((region == nil) or (region == fields[1].upcase)))
      return [ fields[3].to_f, fields[4].to_f ]
    end
    limit = limit - 1
  end
  nil
end

def lookupCity(city, region=nil)
  if region
    require 'states'
    limit = 200000
    region = region.upcase
    if STATE_NORMALIZATION.has_key?(region)
      region = STATE_NORMALIZATION[region]
    end
  else
    limit = 1000
  end
  result = nil
  File.open("us_cities.txt") { |fin|
    result = searchFile(fin, city, region, limit)
  }
  if not result
    File.open("world_cities.txt") { |fin|
      result = searchFile(fin, city, region, limit)
    }
  end
  return result
end

def reportErrors(cgi, errors)
  cgi.out("text/plain") {
    ("The data in the form contained errors in the following fields:\n" +
     errors.to_s +
     "\nPlease try again.\n")
  }
end

KNOWNPAPER = { 
  "2A0" => "2A0",
  "4A0" => "4A0",
  "A0" => "A0",
  "A1" => "A1",
  "A2" => "A2",
  "A3" => "A3",
  "A4" => "A4",
  "A5" => "A5",
  "A6" => "A6",
  "A7" => "A7",
  "A8" => "A8",
  "A9" => "A9",
  "A10" => "A10",
  "B0" => "B0",
  "B1" => "B1",
  "B2" => "B2",
  "B3" => "B3",
  "B4" => "B4",
  "B5" => "B5",
  "B6" => "B6",
  "B7" => "B7",
  "B8" => "B8",
  "B9" => "B9",
  "B10" => "B10",
  "C0" => "C0",
  "C1" => "C1",
  "C2" => "C2",
  "C3" => "C3",
  "C4" => "C4",
  "C5" => "C5",
  "C6" => "C6",
  "C7" => "C7",
  "C8" => "C8",
  "C9" => "C9",
  "C10" => "C10",
  "EXECUTIVE" => "EXECUTIVE",
  "FOLIO" => "FOLIO",
  "LEGAL" => "LEGAL",
  "LETTER" => "LETTER",
  "RA0" => "RA0",
  "RA1" => "RA1",
  "RA2" => "RA2",
  "RA3" => "RA3",
  "RA4" => "RA4",
  "SRA0" => "SRA0",
  "SRA1" => "SRA1",
  "SRA2" => "SRA2",
  "SRA3" => "SRA3",
  "SRA4" => "SRA4",
  "ANSI A" => "LETTER",
  "ANSI B" => [0, 0, PDF::Writer.in2pts(11), PDF::Writer.in2pts(17) ],
  "TABLOID" => [0, 0, PDF::Writer.in2pts(11), PDF::Writer.in2pts(17) ],
  "ANSI C" => [0, 0, PDF::Writer.in2pts(17), PDF::Writer.in2pts(22) ],
  "ANSI D" => [0, 0, PDF::Writer.in2pts(22), PDF::Writer.in2pts(34) ],
  "ANSI E" => [0, 0, PDF::Writer.in2pts(34), PDF::Writer.in2pts(44) ]
}

def bool(v)
  if v and (v.strip =~ /on|true/)
    1
  else
    0
  end
end

def handleRequest(cgi)
  db = SQLite3::Database.new("mapsmade.db") 
  if db
    begin
      db.busy_timeout(150)
      db.execute("CREATE TABLE if not exists log (id integer primary key autoincrement, title text, paper text, bluefill tinyint, view tinyint, countries tinyint, cities tinyint, distance text, location text, datetime bigint, iploc tinyint, referrer text, success tinyint, blackwhite tinyint, latlonglines tinyint, gridsquarelabels tinyint)")
      db.execute("insert into log (title, paper, bluefill, view, countries, cities, distance, location, iploc, referrer, datetime, blackwhite, latlonglines, gridsquarelabels) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                 cgi['title'],
                 cgi['paper'],
                 bool(cgi['bluefill']),
                 bool(cgi['view']),
                 bool(cgi['countries']),
                 bool(cgi['uscities']),
                 cgi['distance'],
                 cgi['location'],
                 bool(cgi['iplocationused']),
                 cgi.referer, Time.now.to_i,
                 bool(cgi['bw']),
                 bool(cgi['latlong']),
                 bool(cgi['gridsquares']))
      id = db.get_first_value("select last_insert_rowid()")
      
      lettersize = "LETTER"
      errors = [ ]
      if cgi["paper"] and KNOWNPAPER[cgi["paper"]]
        lettersize = KNOWNPAPER[cgi["paper"]]
      else
        errors << ("PAPER\nPaper argument is wrong '" + cgi["paper"] + "'\n")
      end
      location = cgi["location"].strip
      if location =~ MAIDENHEADREGEX
        latlong = maidenheadToLatLong($1)
        latitude = latlong[0]
        longitude = latlong[1]
      elsif location =~ /^([-+]?[0-9]+(\.[0-9]*)?)\s*,\s*([-+]?[0-9]+(\.[0-9]*)?)$/
        latitude = $1.to_f
        longitude = $3.to_f
        if latitude.abs > 90.0 or longitude.abs > 180.0
          errors << LOCATIONHELP
        end
      elsif location =~ /^([-+]?[0-9]+(\.[0-9]*)?)\s*([NSns])\s*,\s*([-+]?[0-9]+(\.[0-9]*)?)\s*([EWew])$/
        latitude = $1.to_f
        if $3.upcase == "S"
          latitude = -latitude
        end
        longitude = $4.to_f
        if $6.upcase == "W"
          longitude = -longitude
        end
        if latitude.abs > 90.0 or longitude.abs > 180.0
          errors << LOCATIONHELP
        end
      elsif location =~ /^(\d+)(\s+(\d+)(\s+(\d+))?)?([NnSs])(\s*,\s*|\s+)(\d+)(\s+(\d+)(\s+(\d+))?)?([EeWw])$/
        latitude = $1.to_f
        if $3
          latitude = latitude + $3.to_f/60.0
          if $5
            latitude = latitude + $5.to_f/3600.0
          end
        end
        if $6.upcase == "S"
          latitude = -latitude
        end
        longitude = $8.to_f
        if $10
          longitude = longitude + $10.to_f/60.0
          if $12
            longitude = longitude + $12.to_f/3600.0
          end
        end
        if $13.upcase == "W"
          longitude = -longitude
        end
        if latitude.abs > 90.0 or longitude.abs > 180.0
          errors << LOCATIONHELP
        end
      elsif location =~ /^\w+(-\w+)*\.?(\s+\w+(-\w+)*\.?)*$/
        location.gsub!(/\s\s+/, " ") # normalize spacing if any
        latlong = lookupCity(location)
        if latlong
          latitude = latlong[0]
          longitude = latlong[1]
        else
          errors << LOCATIONHELP
        end
      elsif location =~ /^(\w+(-\w+)*\.?(\s+\w+(-\w+)*\.?)*)\s*,\s*(\w+(-\w+)*\.?(\s+\w+(-\w+)*\.?)*)$/
        city = $1
        region = $5
        city.gsub!(/\s\s+/, " ") # normalize spacing if any
        region.gsub!(/\s\s+/, " ") # normalize spacing if any
        latlong = lookupCity(city, region)
        if latlong
          latitude = latlong[0]
          longitude = latlong[1]
        else
          errors << LOCATIONHELP
        end
      elsif "" == location
        latitude = 180.0*(0.5 - rand)
        longitude = 180.0*(1.0 - 2.0*rand)
      else
        errors << LOCATIONHELP
      end
      distance = cgi["distance"].to_f
      if distance > EARTHRADIUS*Math::PI || distance == 0
        distance = EARTHRADIUS*Math::PI
      end
      
      title = "Azimuthal Map"
      
      if not titleOkay(cgi["title"])
        errors << "TITLE\nTitle has illegal characters.\n"
      else
        title = cgi["title"]
      end


      if errors.length > 0
        reportErrors(cgi, errors)
        db.execute("update log set success = ?, datetime = ? where id = ?",
                   0, Time.now.to_i, id)
      else
        foo = AzimuthWriter.new(distance, lettersize, latitude*DEGTORAD,
                                longitude*DEGTORAD,"on"==cgi["bluefill"], title,
                                "on"==cgi["bw"])

        
        File.open("corrected.bin") { |inf|
          foo.traceFile(inf)
        }
        File.open("nations.bin") { |inf|
          foo.traceLines(inf)
        }
        File.open("states.bin") { |inf|
          foo.traceLines(inf)
        }
        if "on" == cgi["latlong"]
          foo.latlonggrid
        end
        if "on" == cgi["gridsquares"]
          foo.gridsquarelabels
        end
        if "on" == cgi["countries"]
          foo.labelCountries
        end
        if "on" == cgi["states"]
          foo.labelStates
        end
        if "on" == cgi["uscities"]
          foo.labelCities
        end
        headers = { "type" => "application/octet" }
        if "on" == cgi["view"]
          headers["type"] = "application/pdf"
          headers["content-disposition"] = "inline; filename=AzimuthalMap.pdf"
        else
          headers["content-disposition"] = "attachment; filename=AzimuthalMap.pdf"
        end
        foo.frame
        foo.heading
        foo.footer
        # foo.dump(File.open("test.pdf", "wb"))
        contents =   foo.render
        cgi.out(headers) {
          contents
        }
        contents = nil
        foo = nil
        db.execute("update log set success = ?, datetime = ? where id = ?",
                   1, Time.now.to_i, id)
      end
    ensure
      db.close
    end
  end
end


FCGI.each_cgi { |cgi|
  begin
    handleRequest(cgi)
  rescue
    logError(cgi, $!, __FILE__)
    raise
  end
}
# File.open("/tmp/backup.pdf", "wb") { |f| f.write(contents) }
