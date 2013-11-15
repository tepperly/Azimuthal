#!/usr/bin/env ruby
# $Id: mappaper.fcgi 146 2010-05-06 04:27:08Z tepperly $

ENV['GEM_HOME'] = "/home8/brightly/ruby/gems"
require 'rubygems'
require 'sqlite3'
require 'fcgi'
# require 'scruffy'
require 'SVG/Graph/Pie'
require 'cgierror'
require 'cgiparsedate'

def in2pts(val)
  72 * val
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
  "ANSI B" => [0, 0, in2pts(11), in2pts(17) ],
  "TABLOID" => [0, 0, in2pts(11), in2pts(17) ],
  "ANSI C" => [0, 0, in2pts(17), in2pts(22) ],
  "ANSI D" => [0, 0, in2pts(22), in2pts(34) ],
  "ANSI E" => [0, 0, in2pts(34), in2pts(44) ]
}

def filter(data, totalcount)
  if data.size > 12
    tmp = Array.new
    data.each { |k,v|
      tmp.push([k, v])
    }
    tmp.sort! {|x,y| y[1] <=> x[1] }
    result = Hash.new
    11.times { |i|
      result[tmp[i][0]] = tmp[i][1]
    }
    result["Other"] = 0
    11.upto(tmp.size-1) { |i|
      result["Other"] = result["Other"] + tmp[i][1]
    }
  else
    result = data
  end
  result
end

def chooseFields(data)
  keys = data.keys
  keys.sort { |i,j|
    -1 * ( data[i]  <=> data[j])
  }
end

def handleRequest(cgi)
  startingTime = parseDate(cgi["since"])
  datelimit = ""
  if startingTime
    datelimit = "where datetime >= " + startingTime.to_i.to_s
  end
  mcount = 0
  graph = nil
  SQLite3::Database.new("mapsmade.db") { |db|
    data = { }
    db.execute("select paper, count(*) from log " + datelimit + " group by paper order by paper asc") { |row|
      paper = row[0].to_s
      if KNOWNPAPER.has_key?(paper)
        data[paper] = row[1].to_i
        mcount = mcount + data[paper]
      end
    }
#    data.each { |k,v| print "#{k} = #{v}\n" }
    data = filter(data, mcount)
    fields = chooseFields(data)
    graph = SVG::Graph::Pie.new( { :width => 700,
                                   :height => 480,
                                   :title_font_size => 24,
                                   :graph_title => "Map Paper Types",
                                   :show_graph_title => true,
                                   :fields => fields } )
    graph.add_data( {
                      :data => fields.map { |a|  data[a] },
                      :title => "Paper Details" } )
#    print "Filtered\n\n"
#    data.each { |k,v| print "#{k} = #{v}\n" }
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
