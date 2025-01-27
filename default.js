var dataset = <?php echo $dataModel->getGraphDataSetJson(); ?>;

var width = 400,
	height = 400,
	radius = Math.min(width, height) / 2,
	colours = ['#d03a3a', '#6FB437', '#ff7f0e'];

d3.scale.customColours = function()
{
	return d3.scale.ordinal().range(colours);
};

var colour = d3.scale.customColours();
var pie = d3.layout.pie().sort(null);

var arc = d3.svg.arc().innerRadius(radius - 20).outerRadius(radius - 50);
var svg = d3.select("#graph").append("svg")
	.attr("width", width)
	.attr("height", height)
	.append("g")
	.attr("transform", "translate(" + width / 2 + "," + height / 2 + ")");

var path = svg.selectAll("path")
	.data(pie(dataset.memory))
	.enter().append("path")
	.attr("fill", function(d, i) { return colour(i); })
	.attr("d", arc)
	.each(function(d) { this._current = d; }); // store the initial values

d3.selectAll("input").on("change", change);
set_text("memory");

function set_text(t)
{
	if (t === "memory")
	{
		d3.select("#stats").html(
			"<table><tr><th style='background:#d03a3a;color:#fff;'>Used</th><td><?php echo $dataModel->getHumanUsedMemory()?></td></tr>"+
			"<tr><th style='background:#6FB437;color:#fff;'>Free</th><td><?php echo $dataModel->getHumanFreeMemory()?></td></tr>"+
			"<tr><th style='background:#ff7f0e;color:#fff' rowspan=\"2\">Wasted</th><td><?php echo $dataModel->getHumanWastedMemory()?></td></tr>"+
			"<tr><td><?php echo $dataModel->getWastedMemoryPercentage()?>%</td></tr></table>"
			);
	}
	else if (t === "keys")
	{
		d3.select("#stats").html(
			"<table><tr><th style='background:#d03a3a;color:#fff'>Cached keys</th><td>"+format_value(dataset[t][0])+"</td></tr>"+
			"<tr><th style='background:#6FB437;color:#fff'>Free Keys</th><td>"+format_value(dataset[t][1])+"</td></tr></table>"
			);
	}
	else if (t === "hits")
	{
		d3.select("#stats").html(
			"<table><tr><th style='background:#d03a3a;color:#fff'>Misses</th><td>"+format_value(dataset[t][0])+"</td></tr>"+
			"<tr><th style='background:#6FB437;color:#fff'>Cache Hits</th><td>"+format_value(dataset[t][1])+"</td></tr></table>"
			);
	}
	else if (t === "restarts")
	{
		d3.select("#stats").html(
			"<table><tr><th style='background:#d03a3a;color:#fff'>Memory</th><td>"+dataset[t][0]+"</td></tr>"+
			"<tr><th style='background:#6FB437;color:#fff'>Manual</th><td>"+dataset[t][1]+"</td></tr>"+
			"<tr><th style='background:#ff7f0e;color:#fff'>Keys</th><td>"+dataset[t][2]+"</td></tr></table>"
			);
	}
}

function change()
{
	// Skip if the value is undefined for some reason
	if (typeof dataset[this.value] !== 'undefined')
	{
		// Filter out any zero values to see if there is anything left
		var remove_zero_values = dataset[this.value].filter(function(value) { return value > 0;	});
		if (remove_zero_values.length > 0)
		{
			$('#graph').find('> svg').show();
			path = path.data(pie(dataset[this.value])); // update the data
			path.transition().duration(750).attrTween("d", arcTween); // redraw the arcs
			// Hide the graph if we can't draw it correctly, not ideal but this works
		}
		else
		{
			$('#graph').find('> svg').hide();
		}
		
		set_text(this.value);
	}
}

function arcTween(a)
{
	var i = d3.interpolate(this._current, a);
	this._current = i(0);
	return function(t)
	{
		return arc(i(t));
	};
}

function size_for_humans(bytes)
{
	if (bytes > 1048576)
	{
		return (bytes/1048576).toFixed(2) + ' MB';
	}
	else if (bytes > 1024)
	{
		return (bytes/1024).toFixed(2) + ' KB';
	}
	else
		return bytes + ' bytes';
}

function format_value(value)
{
	if (dataset["TSEP"] == 1)
	{
		return value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
	}
	else
	{
		return value;
	}
}

var w = window.innerWidth,
	h = window.innerHeight,
	x = d3.scale.linear().range([0, w]),
	y = d3.scale.linear().range([0, h]);

var vis = d3.select("#partition")
	.style("width", w + "px")
	.style("height", h + "px")
	.append("svg:svg")
	.attr("width", w)
	.attr("height", h);

var partition = d3.layout.partition()
	.value(function(d) { return d.size; });

root = JSON.parse('<?php echo json_encode($dataModel->getD3Scripts()); ?>');

var g = vis.selectAll("g")
	.data(partition.nodes(root))
	.enter().append("svg:g")
	.attr("transform", function(d) { return "translate(" + x(d.y) + "," + y(d.x) + ")"; })
	.on("click", click);

var kx = w / root.dx,
	ky = h / 1;

g.append("svg:rect")
	.attr("width", root.dy * kx)
	.attr("height", function(d) { return d.dx * ky; })
	.attr("class", function(d) { return d.children ? "parent" : "child"; });

g.append("svg:text")
	.attr("transform", transform)
	.attr("dy", ".35em")
	.style("opacity", function(d) { return d.dx * ky > 12 ? 1 : 0; })
	.text(function(d) { return d.name; })

d3.select(window)
	.on("click", function() { click(root); })

function click(d)
{
	if (!d.children)
		return;
	
	kx = (d.y ? w - 40 : w) / (1 - d.y);
	ky = h / d.dx;
	x.domain([d.y, 1]).range([d.y ? 40 : 0, w]);
	y.domain([d.x, d.x + d.dx]);
	
	var t = g.transition()
		.duration(d3.event.altKey ? 7500 : 750)
		.attr("transform", function(d) { return "translate(" + x(d.y) + "," + y(d.x) + ")"; });
	
	t.select("rect")
		.attr("width", d.dy * kx)
		.attr("height", function(d) { return d.dx * ky; });
	
	t.select("text")
		.attr("transform", transform)
		.style("opacity", function(d) { return d.dx * ky > 12 ? 1 : 0; });
	
	d3.event.stopPropagation();
}

function transform(d)
{
	return "translate(8," + d.dx * ky / 2 + ")";
}

$(document).ready(function()
{
	function handleVisualisationToggle(close)
	{
		$('#partition, #close-partition').fadeToggle();
				  
		// Is the visualisation being closed? If so show the status tab again
		if (close) {
			$('#tab-visualise').removeAttr('checked');
			$('#tab-status').trigger('click');
		}
	}
				  
	$('label[for="tab-visualise"], #close-partition').on('click', function()
	{
		handleVisualisationToggle(($(this).attr('id') === 'close-partition'));
	});
				  
	$(document).keyup(function(e)
	{
		if (e.keyCode == 27) handleVisualisationToggle(true);
	});
});
