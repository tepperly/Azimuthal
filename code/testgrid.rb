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
