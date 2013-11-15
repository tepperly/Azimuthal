#!/usr/bin/env ruby
# $Id: mapgraph.fcgi 146 2010-05-06 04:27:08Z tepperly $

ENV['GEM_HOME'] = "/home8/brightly/ruby/gems"
require 'rubygems'
require 'sqlite3'
require 'fcgi'
# require 'scruffy'
require 'SVG/Graph/TimeSeries'
require 'cgierror'
require 'cgiparsedate'

def handleRequest(cgi)
  startingTime = parseDate(cgi["since"])
  datelimit = ""
  xAxisFormat = "%m-%Y"
  if startingTime
    datelimit = " and datetime >= " + startingTime.to_i.to_s
    if (startingTime - Time.now) < 30*7*24*60*60
      xAxisFormat = "%m-%d-%Y"
    end
  end
  graph = SVG::Graph::TimeSeries.new( { :graph_title => "Azimuthal Maps Generated",
                                        :show_graph_title => true,
                                        :title_font_size => 24,
                                        :show_data_values => false,
                                        :x_label_format => xAxisFormat,
                                        :width => 850,
                                        :height => 480
} )
  mcount = 0
  timeperiod = "day"
  SQLite3::Database.new("mapsmade.db") { |db|
    res = db.get_first_value("select min(datetime) from log where datetime > 0 " + datelimit ).to_i
    if (Time.now.to_i-res)/86400.0 > 200
      timeperiod="month"
    end
    data = Array.new
    db.execute("select strftime('%s', datetime(datetime,'unixepoch','start of " + timeperiod + "')) as daynum, count(*) from log where success == 1 and datetime > 0 " + datelimit + " group by daynum order by daynum asc ") { |row|
      daynum = row[0].to_f.to_i
      nummaps = row[1].to_i
      data << Time.at(daynum)
      data << nummaps
    }
    graph.add_data( :data => data, :title => "Map Data" )
  }

  cgi.out("type" => "image/svg+xml") {
    graph.burn
  }
end

FCGI.each_cgi { |cgi|
  begin
    handleRequest(cgi)
  rescue
    logError(cgi, $!, __FILE__)
    raise
  end
}
